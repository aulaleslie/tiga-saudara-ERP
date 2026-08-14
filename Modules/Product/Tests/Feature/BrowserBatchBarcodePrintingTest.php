<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Livewire\BarcodeBatchWorkspace;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BrowserBatchBarcodePrintingTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private User $operator;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'barcodes.print', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'documents.business.override', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'products.access', 'guard_name' => 'web']);

        Setting::truncate();
        $this->settingA = Setting::factory()->create(['company_name' => 'Business A']);
        $this->settingB = Setting::factory()->create(['company_name' => 'Business B']);

        $this->unit = Unit::firstOrCreate(['name' => 'Unit Test', 'short_name' => 'UT']);

        $role = Role::firstOrCreate(['name' => 'barcode-test-role', 'guard_name' => 'web']);

        $this->operator = User::factory()->create();
        $this->operator->givePermissionTo('barcodes.print');
        $this->operator->settings()->attach($this->settingA->id, ['role_id' => $role->id]);
        $this->operator->settings()->attach($this->settingB->id, ['role_id' => $role->id]);
    }

    /** Build a valid EAN-13 value (12 digits + computed check digit). */
    private function ean13(string $twelveDigits): string
    {
        $sum = 0;

        foreach (str_split($twelveDigits) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return $twelveDigits . ((10 - ($sum % 10)) % 10);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'product_name' => 'Produk Uji',
            'product_code' => 'SKU-' . fake()->unique()->numerify('#####'),
            'base_unit_id' => $this->unit->id,
            'setting_id' => $this->settingA->id,
            'barcode' => $this->ean13(fake()->unique()->numerify('############')),
            'product_barcode_symbology' => 'EAN13',
            'product_price' => 100,
            'product_cost' => 80,
            'stock_managed' => true,
        ], $attributes));
    }

    private function setPrice(Product $product, Setting $setting, $salePrice): ProductPrice
    {
        return ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $salePrice,
        ]);
    }

    private function actingAsOperator(): self
    {
        $this->actingAs($this->operator)->withSession(['setting_id' => $this->settingA->id]);

        return $this;
    }

    // --- 4.1 Authorization -------------------------------------------------

    public function test_authorized_user_can_open_barcode_workspace(): void
    {
        $this->actingAsOperator()
            ->get(route('barcode.print'))
            ->assertOk();
    }

    public function test_unauthorized_user_cannot_open_barcode_workspace(): void
    {
        $other = User::factory()->create();
        $other->givePermissionTo('products.access');

        $this->actingAs($other)
            ->withSession(['setting_id' => $this->settingA->id])
            ->get(route('barcode.print'))
            ->assertForbidden();
    }

    public function test_guest_cannot_open_barcode_workspace(): void
    {
        $this->get(route('barcode.print'))->assertRedirect(route('login'));
    }

    public function test_unauthorized_user_cannot_post_batch_print(): void
    {
        $product = $this->makeProduct();
        $this->setPrice($product, $this->settingA, 12500);

        $other = User::factory()->create();
        $other->givePermissionTo('products.access');

        $this->actingAs($other)
            ->withSession(['setting_id' => $this->settingA->id])
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertForbidden();
    }

    public function test_guest_cannot_post_batch_print(): void
    {
        $this->post(route('barcode.batch-print'), [
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ])->assertRedirect(route('login'));
    }

    // --- 4.2 Workspace behavior --------------------------------------------

    public function test_workspace_defaults_to_session_business(): void
    {
        $this->actingAsOperator();

        Livewire::test(BarcodeBatchWorkspace::class)
            ->assertSet('selectedSettingId', $this->settingA->id);
    }

    // --- 4.2.1 Preview Map Resolution (Service) -----------------------------

    public function test_service_resolves_valid_preview_map_for_unique_products(): void
    {
        $product = $this->makeProduct([
            'product_name' => 'Produk Map',
            'product_code' => 'SKU-MAP-01',
            'barcode' => '0123456789012',
            'product_barcode_symbology' => 'EAN13',
        ]);
        $this->setPrice($product, $this->settingA, 15000);

        /** @var \Modules\Product\Services\BarcodeBatchService $service */
        $service = app(\Modules\Product\Services\BarcodeBatchService::class);
        $previewMap = $service->resolvePreviewMap([$product->id, $product->id], $this->settingA->id);

        $this->assertCount(1, $previewMap);
        $this->assertArrayHasKey($product->id, $previewMap);

        $preview = $previewMap[$product->id];
        $this->assertTrue($preview['valid']);
        $this->assertSame((int) $product->id, $preview['product_id']);
        $this->assertSame('Produk Map', $preview['product_name']);
        $this->assertSame('SKU-MAP-01', $preview['product_code']);
        $this->assertSame('SKU-MAP-01', $preview['display_sku']);
        $this->assertSame('0123456789012', $preview['barcode']);
        $this->assertSame('EAN13', $preview['symbology']);
        $this->assertEquals(15000.0, $preview['sale_price']);
        $this->assertStringContainsString('<svg', $preview['svg']);
        $this->assertNull($preview['error']);
    }

    public function test_service_applies_deterministic_sku_truncation_in_preview_map(): void
    {
        $longSku = str_repeat('X', 45);
        $product = $this->makeProduct([
            'product_name' => 'Produk Long SKU',
            'product_code' => $longSku,
            'barcode' => '0123456789012',
            'product_barcode_symbology' => 'EAN13',
        ]);
        $this->setPrice($product, $this->settingA, 20000);

        $service = app(\Modules\Product\Services\BarcodeBatchService::class);
        $previewMap = $service->resolvePreviewMap([$product->id], $this->settingA->id);

        $preview = $previewMap[$product->id];
        $this->assertTrue($preview['valid']);
        $this->assertSame(str_repeat('X', 39) . '…', $preview['display_sku']);
    }

    public function test_service_returns_actionable_errors_in_preview_map(): void
    {
        $service = app(\Modules\Product\Services\BarcodeBatchService::class);

        // 1. Blank barcode
        $blankBarcodeProd = $this->makeProduct(['barcode' => '']);
        $this->setPrice($blankBarcodeProd, $this->settingA, 10000);

        // 2. Invalid explicit EAN-13
        $invalidEanProd = $this->makeProduct([
            'barcode' => '123456',
            'product_barcode_symbology' => 'EAN13',
        ]);
        $this->setPrice($invalidEanProd, $this->settingA, 10000);

        // 3. Missing price row for setting
        $noPriceProd = $this->makeProduct(['barcode' => $this->ean13('111111111111')]);

        // 4. Null sale price
        $nullPriceProd = $this->makeProduct(['barcode' => $this->ean13('222222222222')]);
        $this->setPrice($nullPriceProd, $this->settingA, null);

        $previewMap = $service->resolvePreviewMap([
            $blankBarcodeProd->id,
            $invalidEanProd->id,
            $noPriceProd->id,
            $nullPriceProd->id,
        ], $this->settingA->id);

        $this->assertFalse($previewMap[$blankBarcodeProd->id]['valid']);
        $this->assertStringContainsString('tidak memiliki barcode', $previewMap[$blankBarcodeProd->id]['error']);

        $this->assertFalse($previewMap[$invalidEanProd->id]['valid']);
        $this->assertStringContainsString('tidak valid untuk simbologi EAN-13', $previewMap[$invalidEanProd->id]['error']);

        $this->assertFalse($previewMap[$noPriceProd->id]['valid']);
        $this->assertStringContainsString('tidak memiliki harga jual untuk perusahaan yang dipilih', $previewMap[$noPriceProd->id]['error']);

        $this->assertFalse($previewMap[$nullPriceProd->id]['valid']);
        $this->assertStringContainsString('memiliki harga jual kosong untuk perusahaan yang dipilih', $previewMap[$nullPriceProd->id]['error']);
    }

    // --- 4.2.2 Immediate Row Previews & Business Switching -----------------

    public function test_product_previews_property_is_locked_against_client_mutation(): void
    {
        $this->actingAsOperator();

        $product = $this->makeProduct([
            'product_name' => 'Produk Locked Preview',
            'product_code' => 'SKU-LOCKED-01',
            'barcode' => '0123456789012',
        ]);
        $this->setPrice($product, $this->settingA, 17500);

        session(['setting_id' => $this->settingA->id]);

        $component = Livewire::actingAs($this->operator)
            ->test(BarcodeBatchWorkspace::class)
            ->call('addProduct', ['id' => $product->id]);

        // Attempting to mutate locked productPreviews from the client must be rejected
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [productPreviews]');

        $component->set('productPreviews', [
            $product->id => [
                'valid' => true,
                'product_id' => $product->id,
                'product_name' => 'Hacked Name',
                'product_code' => 'HACKED-SKU',
                'display_sku' => 'HACKED-SKU',
                'barcode' => '0000000000000',
                'symbology' => 'EAN13',
                'sale_price' => 1.0,
                'svg' => '<svg><text>malicious</text></svg>',
                'error' => null,
            ],
        ]);
    }

    public function test_selecting_valid_product_renders_immediate_compact_preview(): void
    {
        $this->actingAsOperator();

        $product = $this->makeProduct([
            'product_name' => 'Produk Instant Preview',
            'product_code' => 'SKU-INSTANT-01',
            'barcode' => '0123456789012',
            'product_barcode_symbology' => 'EAN13',
        ]);
        $this->setPrice($product, $this->settingA, 17500);

        session(['setting_id' => $this->settingA->id]);

        $component = Livewire::actingAs($this->operator)
            ->test(BarcodeBatchWorkspace::class)
            ->assertSet('previewed', false)
            ->call('addProduct', ['id' => $product->id]);

        $component->assertSee('Produk Instant Preview')
            ->assertSee('SKU-INSTANT-01')
            ->assertSee('0123456789012')
            ->assertSee(format_currency(17500), false)
            ->assertSeeHtml('<svg')
            ->assertSet('previewed', false);

        // Verify table header order: Produk -> SKU -> Jumlah Label -> remove -> Pratinjau Label
        $html = $component->html();
        $this->assertMatchesRegularExpression(
            '/<thead>.*?data-testid="barcode-product-header".*?data-testid="barcode-sku-header".*?data-testid="barcode-quantity-header".*?data-testid="barcode-remove-header".*?data-testid="barcode-preview-header".*?<\/thead>/s',
            $html
        );

        // Verify table row cell order: product -> sku -> quantity -> remove -> preview
        $this->assertMatchesRegularExpression(
            '/data-testid="barcode-row-' . $product->id . '".*?data-testid="barcode-product-cell-' . $product->id . '".*?data-testid="barcode-sku-cell-' . $product->id . '".*?data-testid="barcode-quantity-cell-' . $product->id . '".*?data-testid="barcode-remove-cell-' . $product->id . '".*?data-testid="barcode-preview-cell-' . $product->id . '"/s',
            $html
        );

        $previewMap = $component->get('productPreviews');
        $this->assertArrayHasKey($product->id, $previewMap);
        $this->assertTrue($previewMap[$product->id]['valid']);
    }

    public function test_quantity_changes_keep_one_compact_preview_per_unique_product(): void
    {
        $this->actingAsOperator();

        $product = $this->makeProduct([
            'product_name' => 'Produk Multi Qty',
            'product_code' => 'SKU-MULTI-QTY',
            'barcode' => '0123456789012',
        ]);
        $this->setPrice($product, $this->settingA, 12500);

        session(['setting_id' => $this->settingA->id]);

        $component = Livewire::actingAs($this->operator)
            ->test(BarcodeBatchWorkspace::class)
            ->call('addProduct', ['id' => $product->id])
            ->set('rows.0.quantity', 5);

        $this->assertSame(5, $component->get('totalLabels'));
        $previewMap = $component->get('productPreviews');
        $this->assertCount(1, $previewMap);
        $this->assertArrayHasKey($product->id, $previewMap);

        // Add same product again to test merge
        $component->call('addProduct', ['id' => $product->id]);
        $this->assertSame(6, $component->get('totalLabels'));
        $this->assertCount(1, $component->get('rows'));
        $this->assertCount(1, $component->get('productPreviews'));
    }

    public function test_business_change_refreshes_selected_row_preview_prices(): void
    {
        $this->operator->givePermissionTo('documents.business.override');
        $this->actingAsOperator();

        $product = $this->makeProduct(['product_name' => 'Dynamic Price Item', 'barcode' => '0123456789012']);
        $this->setPrice($product, $this->settingA, 12000);
        $this->setPrice($product, $this->settingB, 22000);

        session(['setting_id' => $this->settingA->id]);

        $component = Livewire::actingAs($this->operator)
            ->test(BarcodeBatchWorkspace::class)
            ->call('addProduct', ['id' => $product->id])
            ->assertSee(format_currency(12000), false)
            ->assertDontSee(format_currency(22000), false);

        $component->call('businessChanged', ['settingId' => $this->settingB->id])
            ->assertSee(format_currency(22000), false)
            ->assertDontSee(format_currency(12000), false);

        $previewMap = $component->get('productPreviews');
        $this->assertEquals(22000.0, $previewMap[$product->id]['sale_price']);
    }

    public function test_row_shows_actionable_inline_errors_for_invalid_product_state(): void
    {
        $this->actingAsOperator();

        $blankBarcodeProd = $this->makeProduct(['product_name' => 'Blank Barcode', 'barcode' => '']);
        $this->setPrice($blankBarcodeProd, $this->settingA, 10000);

        $invalidEanProd = $this->makeProduct([
            'product_name' => 'Invalid EAN',
            'barcode' => '9999',
            'product_barcode_symbology' => 'EAN13',
        ]);
        $this->setPrice($invalidEanProd, $this->settingA, 10000);

        $noPriceProd = $this->makeProduct(['product_name' => 'No Price Prod', 'barcode' => $this->ean13('333333333333')]);

        session(['setting_id' => $this->settingA->id]);

        $component = Livewire::actingAs($this->operator)
            ->test(BarcodeBatchWorkspace::class)
            ->call('addProduct', ['id' => $blankBarcodeProd->id])
            ->call('addProduct', ['id' => $invalidEanProd->id])
            ->call('addProduct', ['id' => $noPriceProd->id]);

        $component->assertSee('tidak memiliki barcode')
            ->assertSee('tidak valid untuk simbologi EAN-13')
            ->assertSee('tidak memiliki harga jual untuk perusahaan yang dipilih');

        // Verify batch preview / print still rejects
        $component->call('preview');
        $this->assertNotEmpty($component->get('batchErrors'));
        $this->assertFalse($component->get('previewed'));
    }

    public function test_workspace_adds_rows_and_totals_labels(): void
    {
        $this->actingAsOperator();

        $a = $this->makeProduct(['product_name' => 'Produk A']);
        $b = $this->makeProduct(['product_name' => 'Produk B']);

        $component = Livewire::test(BarcodeBatchWorkspace::class)
            ->call('addProduct', ['id' => $a->id])
            ->call('addProduct', ['id' => $b->id])
            ->set('rows.0.quantity', 3)
            ->set('rows.1.quantity', 2);

        $this->assertCount(2, $component->get('rows'));
        $component->assertSee('5');
    }

    public function test_selecting_an_existing_product_merges_into_one_row(): void
    {
        $this->actingAsOperator();

        $a = $this->makeProduct();

        $component = Livewire::test(BarcodeBatchWorkspace::class)
            ->call('addProduct', ['id' => $a->id])
            ->call('addProduct', ['id' => $a->id]);

        $rows = $component->get('rows');
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['quantity']);
    }

    public function test_workspace_removes_rows(): void
    {
        $this->actingAsOperator();

        $a = $this->makeProduct();
        $b = $this->makeProduct();

        $component = Livewire::test(BarcodeBatchWorkspace::class)
            ->call('addProduct', ['id' => $a->id])
            ->call('addProduct', ['id' => $b->id])
            ->call('removeRow', 0);

        $rows = $component->get('rows');
        $this->assertCount(1, $rows);
        $this->assertSame($b->id, $rows[0]['product_id']);
    }

    public function test_preview_uses_prices_of_the_selected_business(): void
    {
        $this->operator->givePermissionTo('documents.business.override');
        $this->actingAsOperator();

        $product = $this->makeProduct();
        $this->setPrice($product, $this->settingA, 12500);
        $this->setPrice($product, $this->settingB, 20000);

        $component = Livewire::test(BarcodeBatchWorkspace::class)
            ->call('addProduct', ['id' => $product->id])
            ->call('preview');

        $this->assertSame(12500.0, $component->get('previewLabels')[0]['sale_price']);

        $component->call('businessChanged', ['settingId' => $this->settingB->id])
            ->call('preview');

        $this->assertSame(20000.0, $component->get('previewLabels')[0]['sale_price']);
    }

    public function test_preview_rejects_business_the_user_cannot_access(): void
    {
        $this->actingAsOperator();

        $foreign = Setting::factory()->create(['company_name' => 'Business C']);
        $product = $this->makeProduct();
        $this->setPrice($product, $this->settingA, 12500);

        $component = Livewire::test(BarcodeBatchWorkspace::class)
            ->call('addProduct', ['id' => $product->id])
            ->call('businessChanged', ['settingId' => $foreign->id])
            ->call('preview');

        $this->assertNotEmpty($component->get('batchErrors'));
        $this->assertSame([], $component->get('previewLabels'));
    }

    // --- Workspace print gate: errors stay visible, no print tab opens -------

    /**
     * @dataProvider invalidWorkspaceBatchProvider
     */
    public function test_print_action_shows_errors_in_workspace_without_opening_print_tab(string $scenario): void
    {
        $this->actingAsOperator();

        $settingId = $this->settingA->id;

        switch ($scenario) {
            case 'missing_price':
                $product = $this->makeProduct();
                break;
            case 'blank_barcode':
                $product = $this->makeProduct(['barcode' => null]);
                $this->setPrice($product, $this->settingA, 12500);
                break;
            case 'invalid_explicit_ean13':
                $product = $this->makeProduct([
                    'barcode' => '0123456789013',
                    'product_barcode_symbology' => 'EAN13',
                ]);
                $this->setPrice($product, $this->settingA, 12500);
                break;
            case 'inaccessible_business':
                $product = $this->makeProduct();
                $this->setPrice($product, $this->settingA, 12500);
                $settingId = Setting::factory()->create(['company_name' => 'Business Z'])->id;
                break;
        }

        $component = Livewire::test(BarcodeBatchWorkspace::class)
            ->call('addProduct', ['id' => $product->id])
            ->call('businessChanged', ['settingId' => $settingId])
            ->call('print');

        $errors = $component->get('batchErrors');

        $this->assertNotEmpty($errors, "Scenario {$scenario} must surface an operator-visible error.");
        $this->assertSame([], $component->get('previewLabels'));
        $this->assertFalse($component->get('previewed'));

        // The print tab is only opened by the 'barcode-batch-ready' event.
        $component->assertNotDispatched('barcode-batch-ready')
            ->assertDispatched('barcode-batch-invalid');

        // The error text is rendered in the active workspace.
        $component->assertSee($errors[0]);
    }

    public static function invalidWorkspaceBatchProvider(): array
    {
        return [
            'missing selected-business price' => ['missing_price'],
            'blank barcode' => ['blank_barcode'],
            'explicit invalid EAN-13' => ['invalid_explicit_ean13'],
            'inaccessible business' => ['inaccessible_business'],
        ];
    }

    public function test_print_action_dispatches_ready_for_a_valid_batch(): void
    {
        $this->actingAsOperator();

        $product = $this->makeProduct();
        $this->setPrice($product, $this->settingA, 12500);

        Livewire::test(BarcodeBatchWorkspace::class)
            ->call('addProduct', ['id' => $product->id])
            ->call('print')
            ->assertSet('batchErrors', [])
            ->assertDispatched('barcode-batch-ready')
            ->assertNotDispatched('barcode-batch-invalid');
    }

    // --- 4.3 Endpoint validation -------------------------------------------

    public function test_endpoint_requires_items(): void
    {
        $this->actingAsOperator()
            ->post(route('barcode.batch-print'), ['setting_id' => $this->settingA->id])
            ->assertSessionHasErrors('items');
    }

    /**
     * @dataProvider invalidQuantityProvider
     */
    public function test_endpoint_rejects_invalid_quantities($quantity): void
    {
        $product = $this->makeProduct();
        $this->setPrice($product, $this->settingA, 12500);

        $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            ])
            ->assertSessionHasErrors('items.0.quantity');
    }

    public static function invalidQuantityProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'fractional' => [1.5],
            'non numeric' => ['abc'],
            'above per-product cap' => [101],
        ];
    }

    public function test_endpoint_rejects_batches_above_the_total_cap(): void
    {
        $a = $this->makeProduct();
        $b = $this->makeProduct();
        $c = $this->makeProduct();
        foreach ([$a, $b, $c] as $product) {
            $this->setPrice($product, $this->settingA, 12500);
        }

        $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [
                    ['product_id' => $a->id, 'quantity' => 100],
                    ['product_id' => $b->id, 'quantity' => 100],
                    ['product_id' => $c->id, 'quantity' => 1],
                ],
            ])
            ->assertSessionHasErrors('items');
    }

    public function test_endpoint_rejects_unknown_products(): void
    {
        $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => 999999, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('items.0.product_id');
    }

    public function test_endpoint_rejects_blank_barcode(): void
    {
        $product = $this->makeProduct(['barcode' => null]);
        $this->setPrice($product, $this->settingA, 12500);

        $this->actingAsOperator()
            ->from(route('barcode.print'))
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertRedirect(route('barcode.print'))
            ->assertSessionHasErrors();
    }

    public function test_endpoint_accepts_absent_symbology_with_valid_ean13_barcode_and_preserves_leading_zeroes(): void
    {
        $validEan = '0123456789012';
        $product = $this->makeProduct([
            'barcode' => $validEan,
            'product_barcode_symbology' => null,
        ]);
        $this->setPrice($product, $this->settingA, 12500);

        $response = $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertOk();

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<svg'));
        $this->assertStringContainsString($validEan, $html);
    }

    public function test_endpoint_accepts_absent_symbology_with_non_ean13_barcode_renders_c128(): void
    {
        $product = $this->makeProduct([
            'barcode' => 'LEGACY-SKU-123',
            'product_barcode_symbology' => null,
        ]);
        $this->setPrice($product, $this->settingA, 12500);

        $response = $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertOk();

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<svg'));
        $this->assertStringContainsString('LEGACY-SKU-123', $html);
    }

    public function test_endpoint_accepts_unrecognized_symbology_with_non_ean13_barcode_renders_c128(): void
    {
        $product = $this->makeProduct([
            'barcode' => 'CUSTOM-CODE',
            'product_barcode_symbology' => 'UNRECOGNIZED_TYPE',
        ]);
        $this->setPrice($product, $this->settingA, 12500);

        $response = $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertOk();

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<svg'));
        $this->assertStringContainsString('CUSTOM-CODE', $html);
    }

    // --- Label payload symbology resolution ------------------------------------

    public function test_label_payload_resolves_inferred_valid_ean13_when_symbology_absent(): void
    {
        $validEan = '0123456789012';
        $product = $this->makeProduct([
            'barcode' => $validEan,
            'product_barcode_symbology' => null,
        ]);
        $this->setPrice($product, $this->settingA, 12500);

        $service = app(\Modules\Product\Services\BarcodeBatchService::class);
        $errors = [];
        $label = $service->buildLabel($product, $this->settingA->id, $errors);

        $this->assertNotNull($label, 'Valid EAN-13 with absent symbology must produce a label');
        $this->assertSame('EAN13', $label['symbology']);
    }

    public function test_label_payload_resolves_inferred_valid_ean13_with_leading_zero(): void
    {
        $validEan = '0000000000000';
        $product = $this->makeProduct([
            'barcode' => $validEan,
            'product_barcode_symbology' => null,
        ]);
        $this->setPrice($product, $this->settingA, 12500);

        $service = app(\Modules\Product\Services\BarcodeBatchService::class);
        $errors = [];
        $label = $service->buildLabel($product, $this->settingA->id, $errors);

        $this->assertNotNull($label, 'Valid EAN-13 with leading zeros must produce a label');
        $this->assertSame('EAN13', $label['symbology']);
        $this->assertSame($validEan, $label['barcode']);
    }

    public function test_label_payload_resolves_c128_for_absent_symbology_with_non_ean_barcode(): void
    {
        $product = $this->makeProduct([
            'barcode' => 'LEGACY-SKU-123',
            'product_barcode_symbology' => null,
        ]);
        $this->setPrice($product, $this->settingA, 12500);

        $service = app(\Modules\Product\Services\BarcodeBatchService::class);
        $errors = [];
        $label = $service->buildLabel($product, $this->settingA->id, $errors);

        $this->assertNotNull($label, 'Non-EAN barcode with absent symbology must produce a label');
        $this->assertSame('C128', $label['symbology']);
    }

    public function test_label_payload_resolves_c128_for_unrecognized_symbology_with_non_ean_barcode(): void
    {
        $product = $this->makeProduct([
            'barcode' => 'UNKNOWN-CODE-999',
            'product_barcode_symbology' => 'UNRECOGNIZED',
        ]);
        $this->setPrice($product, $this->settingA, 12500);

        $service = app(\Modules\Product\Services\BarcodeBatchService::class);
        $errors = [];
        $label = $service->buildLabel($product, $this->settingA->id, $errors);

        $this->assertNotNull($label, 'Non-EAN barcode with unrecognized symbology must produce a label');
        $this->assertSame('C128', $label['symbology']);
    }

    public function test_label_payload_resolves_c128_for_recognized_non_ean_symbologies(): void
    {
        $service = app(\Modules\Product\Services\BarcodeBatchService::class);

        // C39 with a valid C39 barcode
        $c39Product = $this->makeProduct([
            'barcode' => 'C39-CODE-123',
            'product_barcode_symbology' => 'C39',
        ]);
        $this->setPrice($c39Product, $this->settingA, 12500);

        $errors = [];
        $c39Label = $service->buildLabel($c39Product, $this->settingA->id, $errors);
        $this->assertNotNull($c39Label, 'C39 barcode must produce a label');
        $this->assertSame('C39', $c39Label['symbology']);

        // UPCA with a valid UPCA barcode
        $upcaProduct = $this->makeProduct([
            'barcode' => '01234567890',
            'product_barcode_symbology' => 'UPCA',
        ]);
        $this->setPrice($upcaProduct, $this->settingA, 12500);

        $errors = [];
        $upcaLabel = $service->buildLabel($upcaProduct, $this->settingA->id, $errors);
        $this->assertNotNull($upcaLabel, 'UPCA barcode must produce a label');
        $this->assertSame('UPCA', $upcaLabel['symbology']);

        // EAN8 with a valid EAN8 barcode
        $ean8Product = $this->makeProduct([
            'barcode' => '96385074',
            'product_barcode_symbology' => 'EAN8',
        ]);
        $this->setPrice($ean8Product, $this->settingA, 12500);

        $errors = [];
        $ean8Label = $service->buildLabel($ean8Product, $this->settingA->id, $errors);
        $this->assertNotNull($ean8Label, 'EAN8 barcode must produce a label');
        $this->assertSame('EAN8', $ean8Label['symbology']);
    }

    public function test_label_payload_resolves_c128_for_stored_c128_alias(): void
    {
        $product = $this->makeProduct([
            'barcode' => 'CODE-WITH-SPACES',
            'product_barcode_symbology' => 'CODE128',
        ]);
        $this->setPrice($product, $this->settingA, 12500);

        $service = app(\Modules\Product\Services\BarcodeBatchService::class);
        $errors = [];
        $label = $service->buildLabel($product, $this->settingA->id, $errors);

        $this->assertNotNull($label, 'CODE128 alias must produce a label');
        $this->assertSame('C128', $label['symbology']);
    }

    public function test_endpoint_rejects_barcode_the_symbology_cannot_encode(): void
    {
        // Valid EAN-13 length but a deliberately wrong check digit.
        $product = $this->makeProduct([
            'barcode' => '0123456789013',
            'product_barcode_symbology' => 'EAN13',
        ]);
        $this->setPrice($product, $this->settingA, 12500);

        $this->actingAsOperator()
            ->from(route('barcode.print'))
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertRedirect(route('barcode.print'))
            ->assertSessionHasErrors();
    }

    public function test_endpoint_rejects_inaccessible_business(): void
    {
        $foreign = Setting::factory()->create(['company_name' => 'Business D']);
        $product = $this->makeProduct();
        $this->setPrice($product, $this->settingA, 12500);

        $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $foreign->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertForbidden();
    }

    // --- 4.4 Selected-business price authority ------------------------------

    public function test_endpoint_uses_selected_business_sale_price_exclusively(): void
    {
        $this->operator->givePermissionTo('documents.business.override');

        $product = $this->makeProduct();
        $priceA = $this->setPrice($product, $this->settingA, 12500);
        $priceA->update(['tier_1_price' => 999]);
        $this->setPrice($product, $this->settingB, 20000);

        $response = $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingB->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertOk();

        $response->assertSee(format_currency(20000), false);
        $response->assertDontSee(format_currency(12500), false);
        $response->assertDontSee(format_currency(999), false);
    }

    public function test_endpoint_rejects_missing_selected_business_price_row(): void
    {
        $product = $this->makeProduct();
        $this->setPrice($product, $this->settingB, 20000);

        $this->actingAsOperator()
            ->from(route('barcode.print'))
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors();
    }

    public function test_endpoint_rejects_null_sale_price_without_falling_back(): void
    {
        $product = $this->makeProduct();
        $this->setPrice($product, $this->settingA, null);

        $this->actingAsOperator()
            ->from(route('barcode.print'))
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors()
            ->assertRedirect(route('barcode.print'));
    }

    // --- 4.5 Rendering ------------------------------------------------------

    public function test_batch_expands_quantities_in_requested_order(): void
    {
        $a = $this->makeProduct(['product_name' => 'Produk A']);
        $b = $this->makeProduct(['product_name' => 'Produk B']);
        $this->setPrice($a, $this->settingA, 12500);
        $this->setPrice($b, $this->settingA, 7500);

        $response = $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [
                    ['product_id' => $a->id, 'quantity' => 3],
                    ['product_id' => $b->id, 'quantity' => 2],
                ],
            ])->assertOk();

        $html = $response->getContent();

        $this->assertSame(5, substr_count($html, 'class="label-page"'));
        $nameA = $a->fresh()->product_name;
        $nameB = $b->fresh()->product_name;

        $this->assertSame(3, substr_count($html, $nameA));
        $this->assertSame(2, substr_count($html, $nameB));
        $this->assertLessThan(
            strpos($html, $nameB),
            strrpos($html, $nameA),
            'All Produk A labels must precede Produk B labels.'
        );
    }

    public function test_label_carries_all_required_fields(): void
    {
        $product = $this->makeProduct([
            'product_name' => 'Produk Lengkap',
            'product_code' => 'SKU-LENGKAP',
            'barcode' => '0123456789012',
        ]);
        $this->setPrice($product, $this->settingA, 12500);

        $response = $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertOk();

        $response->assertSee($product->fresh()->product_name);
        $response->assertSee($product->fresh()->product_code);
        $response->assertSee('0123456789012');
        $response->assertSee(format_currency(12500), false);
        $response->assertSee('<svg', false);
    }

    public function test_ean13_and_code128_products_render_svg(): void
    {
        $ean = $this->makeProduct(['barcode' => '0123456789012', 'product_barcode_symbology' => 'EAN13']);
        $c128 = $this->makeProduct(['barcode' => 'ABC-128', 'product_barcode_symbology' => 'C128']);
        $this->setPrice($ean, $this->settingA, 12500);
        $this->setPrice($c128, $this->settingA, 7500);

        $response = $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [
                    ['product_id' => $ean->id, 'quantity' => 1],
                    ['product_id' => $c128->id, 'quantity' => 1],
                ],
            ])->assertOk();

        $html = $response->getContent();

        $this->assertSame(2, substr_count($html, '<svg'));
        $this->assertStringNotContainsString('&lt;svg', $html);
        $this->assertStringContainsString('0123456789012', $html);
        $this->assertStringContainsString('ABC-128', $html);
    }

    public function test_stored_code128_alias_renders_a_printable_label(): void
    {
        $product = $this->makeProduct([
            'barcode' => 'ALIAS-128',
            'product_barcode_symbology' => 'CODE128',
        ]);
        $this->setPrice($product, $this->settingA, 12500);

        $response = $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertOk();

        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, '<svg'));
        $this->assertStringContainsString('ALIAS-128', $html);
    }

    public function test_symbology_aliases_normalize_to_renderer_spellings(): void
    {
        $service = app(\Modules\Product\Services\BarcodeBatchService::class);

        $this->assertSame('C128', $service->normalizeSymbology('CODE128'));
        $this->assertSame('C128', $service->normalizeSymbology('code 128'));
        $this->assertSame('C39', $service->normalizeSymbology('CODE39'));
        $this->assertSame('EAN13', $service->normalizeSymbology('EAN-13'));

        // Already-supported spellings are preserved unchanged.
        foreach (\Modules\Product\Services\BarcodeBatchService::SUPPORTED_SYMBOLOGIES as $supported) {
            $this->assertSame($supported, $service->normalizeSymbology($supported));
        }

        // Unknown values stay unknown so validation can reject them.
        $this->assertSame('NOT_A_SYMBOLOGY', $service->normalizeSymbology('not_a_symbology'));
    }

    // --- SKU display rule (40 chars, then explicit Unicode ellipsis) --------

    public function test_sku_of_exactly_forty_characters_renders_in_full(): void
    {
        $sku = str_repeat('A', 40);

        $html = $this->printLabelForSku($sku);
        $body = $this->labelBody($html);

        $this->assertStringContainsString($sku, $body);
        $this->assertStringNotContainsString('…', $body);
    }

    public function test_sku_of_forty_one_characters_is_truncated_with_ellipsis(): void
    {
        // Distinct trailing characters prove exactly 39 are kept.
        $sku = str_repeat('A', 39) . 'BC';
        $this->assertSame(41, mb_strlen($sku));

        $body = $this->labelBody($this->printLabelForSku($sku));

        $this->assertStringContainsString(str_repeat('A', 39) . '…', $body);
        $this->assertStringNotContainsString($sku, $body);
        $this->assertStringNotContainsString('B', $body);
    }

    public function test_maximum_length_sku_uses_the_same_truncation_rule(): void
    {
        $sku = str_repeat('A', 39) . str_repeat('B', 216);
        $this->assertSame(255, mb_strlen($sku));

        $body = $this->labelBody($this->printLabelForSku($sku));

        $this->assertStringContainsString(str_repeat('A', 39) . '…', $body);
        $this->assertStringNotContainsString($sku, $body);
        $this->assertStringNotContainsString('B', $body);
    }

    public function test_stored_sku_and_barcode_value_are_unaffected_by_label_truncation(): void
    {
        $sku = str_repeat('A', 39) . str_repeat('B', 216);
        $barcode = $this->ean13('999888777666');

        $product = $this->makeProduct(['product_code' => $sku, 'barcode' => $barcode]);
        $this->setPrice($product, $this->settingA, 12500);

        $html = $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertOk()->getContent();

        // Stored value is untouched.
        $this->assertSame($sku, $product->fresh()->product_code);

        // Barcode value stays complete and machine-readable on the label.
        $this->assertStringContainsString($barcode, $this->labelBody($html));

        // Truncation is explicit, never delegated to CSS.
        $this->assertStringNotContainsString('text-overflow: ellipsis', $html);
        $this->assertStringNotContainsString('white-space: nowrap', $html);

        // The SKU is not shrunk below the standard label font to fit.
        $this->assertStringNotContainsString('sku-very-long', $html);
        $this->assertStringNotContainsString('sku-long', $html);
    }

    public function test_workspace_retains_the_full_sku_for_over_limit_values(): void
    {
        $this->actingAsOperator();

        $sku = str_repeat('A', 39) . str_repeat('B', 216);
        $product = $this->makeProduct(['product_code' => $sku]);
        $this->setPrice($product, $this->settingA, 12500);

        // Truncation applies to the physical label only, not the workspace.
        Livewire::test(BarcodeBatchWorkspace::class)
            ->call('addProduct', ['id' => $product->id])
            ->call('preview')
            ->assertSee($sku);
    }

    public function test_display_sku_rule_is_deterministic(): void
    {
        $rule = fn (string $sku) => \Modules\Product\Services\BarcodeBatchService::displaySku($sku);

        $this->assertSame('SKU-001', $rule('SKU-001'));
        $this->assertSame(str_repeat('A', 40), $rule(str_repeat('A', 40)));
        $this->assertSame(str_repeat('A', 39) . '…', $rule(str_repeat('A', 41)));
        $this->assertSame(str_repeat('A', 39) . '…', $rule(str_repeat('A', 255)));
        $this->assertSame(40, mb_strlen($rule(str_repeat('A', 255))));
        $this->assertSame('', $rule(''));
    }

    /** Print a one-label batch for the given SKU and return the document HTML. */
    private function printLabelForSku(string $sku): string
    {
        $product = $this->makeProduct(['product_code' => $sku]);
        $this->setPrice($product, $this->settingA, 12500);

        return $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertOk()->getContent();
    }

    /** The physical label body only, excluding <head>/CSS and the toolbar. */
    private function labelBody(string $html): string
    {
        $start = strpos($html, '<div class="label-page"');
        $this->assertNotFalse($start, 'Print document must contain a label page.');

        return substr($html, $start);
    }

    public function test_print_document_prints_once_and_offers_a_fallback(): void
    {
        $product = $this->makeProduct();
        $this->setPrice($product, $this->settingA, 12500);

        $response = $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
            ])->assertOk();

        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, "window.addEventListener('load'"));
        $this->assertSame(1, substr_count($html, 'id="manual-print-button"'));
        $this->assertStringContainsString('onclick="window.print()"', $html);
    }

    public function test_print_css_matches_physical_label_dimensions(): void
    {
        $product = $this->makeProduct();
        $this->setPrice($product, $this->settingA, 12500);

        $response = $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('size: 55mm 40mm;', $html);
        $this->assertStringContainsString('margin: 0;', $html);
        $this->assertStringContainsString('width: 55mm;', $html);
        $this->assertStringContainsString('height: 40mm;', $html);
        $this->assertStringContainsString('padding: 2mm;', $html);
        $this->assertStringContainsString('overflow: hidden;', $html);
        $this->assertStringContainsString('page-break-after: always;', $html);
    }

    // --- Diagnostic sheet (physical acceptance support) ---------------------

    public function test_diagnostic_sheet_produces_uniquely_sequenced_labels(): void
    {
        $response = $this->actingAsOperator()
            ->get(route('barcode.diagnostic-print', ['count' => 100]))
            ->assertOk();

        $html = $response->getContent();

        $this->assertSame(100, substr_count($html, 'class="label-page"'));
        $this->assertSame(100, substr_count($html, '<svg'));

        // TEST 001 through TEST 100, each appearing exactly twice
        // (sequence heading + barcode value text).
        foreach ([1, 2, 50, 99, 100] as $n) {
            $sequence = 'TEST ' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $this->assertSame(2, substr_count($html, $sequence), "{$sequence} must be uniquely present.");
        }
    }

    public function test_diagnostic_labels_carry_border_and_alignment_markers(): void
    {
        $html = $this->actingAsOperator()
            ->get(route('barcode.diagnostic-print', ['count' => 3]))
            ->assertOk()
            ->getContent();

        // Border drawn on the 2mm safe-area boundary.
        $this->assertStringContainsString('padding: 2mm;', $html);
        $this->assertStringContainsString('class="diagnostic-frame"', $html);
        $this->assertStringContainsString('border: 0.3mm solid #000;', $html);

        // Top and bottom alignment markers on every label.
        $this->assertSame(6, substr_count($html, 'class="alignment-marker"'));

        // Production label geometry is unchanged.
        $this->assertStringContainsString('size: 55mm 40mm;', $html);
        $this->assertStringContainsString('width: 55mm;', $html);
        $this->assertStringContainsString('height: 40mm;', $html);
    }

    public function test_diagnostic_sheet_is_isolated_from_product_label_printing(): void
    {
        $product = $this->makeProduct(['product_name' => 'Produk Nyata']);
        $this->setPrice($product, $this->settingA, 12500);

        $diagnostic = $this->actingAsOperator()
            ->get(route('barcode.diagnostic-print', ['count' => 2]))
            ->assertOk()
            ->getContent();

        // No product data leaks into the diagnostic sheet.
        $this->assertStringNotContainsString($product->fresh()->product_name, $diagnostic);
        $this->assertStringNotContainsString('label-sku', $diagnostic);
        $this->assertStringContainsString('UJI CETAK DIAGNOSTIK', $diagnostic);

        // Normal product labels carry no diagnostic markings.
        $production = $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertOk()->getContent();

        $this->assertStringNotContainsString('alignment-marker', $production);
        $this->assertStringNotContainsString('diagnostic-frame', $production);
        $this->assertStringNotContainsString('TEST 001', $production);
    }

    public function test_diagnostic_sheet_requires_permission(): void
    {
        $other = User::factory()->create();
        $other->givePermissionTo('products.access');

        $this->actingAs($other)
            ->withSession(['setting_id' => $this->settingA->id])
            ->get(route('barcode.diagnostic-print'))
            ->assertForbidden();
    }

    public function test_diagnostic_sheet_is_unavailable_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->actingAsOperator()
            ->get(route('barcode.diagnostic-print'))
            ->assertNotFound();
    }

    public function test_print_document_shows_driver_guidance(): void
    {
        $product = $this->makeProduct();
        $this->setPrice($product, $this->settingA, 12500);

        $this->actingAsOperator()
            ->post(route('barcode.batch-print'), [
                'setting_id' => $this->settingA->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertOk()
            ->assertSee('ECO80BT')
            ->assertSee('data-testid="printer-guidance"', false);
    }

    // --- BarcodeProductSearch: primary barcode lookup ---------------------

    public function test_search_suggestions_show_primary_barcode_and_authorized_selected_business_price(): void
    {
        $this->actingAsOperator();

        $product = $this->makeProduct(['product_name' => 'Widget Suggestion', 'barcode' => '1234567890123']);
        $this->setPrice($product, $this->settingA, 12500);

        session(['setting_id' => $this->settingA->id]);

        $test = Livewire::actingAs($this->operator)
            ->test(\Modules\Product\Livewire\BarcodeProductSearch::class, [
                'selectedSettingId' => $this->settingA->id,
            ])
            ->set('query', 'Widget')
            ->assertSee('Widget Suggestion')
            ->assertSee($product->product_code)
            ->assertSee('1234567890123')
            ->assertSee(format_currency(12500), false);

        $results = $test->get('search_results');
        $this->assertNotEmpty($results);
        $this->assertSame('1234567890123', $results[0]['barcode']);
        $this->assertEquals(12500.0, $results[0]['sale_price']);
    }

    public function test_workspace_renders_search_with_selected_setting_and_stable_key(): void
    {
        $this->actingAsOperator();

        session(['setting_id' => $this->settingA->id]);

        $workspace = Livewire::actingAs($this->operator)
            ->test(BarcodeBatchWorkspace::class);

        $html = $workspace->html();

        // Workspace memo snapshot records the child component keyed by 'barcode-product-search'
        $this->assertStringContainsString('&quot;barcode-product-search&quot;', $html);
    }

    public function test_search_suggestions_update_when_selected_business_changes(): void
    {
        $this->operator->givePermissionTo('documents.business.override');
        $this->actingAsOperator();

        $product = $this->makeProduct(['product_name' => 'Multi-Price Widget', 'barcode' => '1234567890123']);
        $this->setPrice($product, $this->settingA, 12500);
        $this->setPrice($product, $this->settingB, 18500);

        session(['setting_id' => $this->settingA->id]);

        // 1. Initial mount with Setting A
        $componentA = Livewire::actingAs($this->operator)
            ->test(\Modules\Product\Livewire\BarcodeProductSearch::class, [
                'selectedSettingId' => $this->settingA->id,
            ])
            ->set('query', 'Multi-Price')
            ->assertSee(format_currency(12500), false)
            ->assertDontSee(format_currency(18500), false);

        $resultsA = $componentA->get('search_results');
        $this->assertSame('Multi-Price', $componentA->get('query'));
        $this->assertCount(1, $resultsA);
        $this->assertEquals(12500.0, $resultsA[0]['sale_price']);

        // 2. Mounted with Setting B (simulating reactive parent update where query is retained)
        $componentB = Livewire::actingAs($this->operator)
            ->test(\Modules\Product\Livewire\BarcodeProductSearch::class, [
                'selectedSettingId' => $this->settingB->id,
            ])
            ->set('query', 'Multi-Price')
            ->assertSee(format_currency(18500), false)
            ->assertDontSee(format_currency(12500), false);

        $resultsB = $componentB->get('search_results');
        $this->assertSame('Multi-Price', $componentB->get('query'));
        $this->assertCount(1, $resultsB);
        $this->assertEquals(18500.0, $resultsB[0]['sale_price']);
    }

    public function test_search_suggestions_clear_when_business_switches_to_unauthorized_setting(): void
    {
        $otherSetting = Setting::factory()->create(['company_name' => 'Unauthorized Business']);

        $user = User::factory()->create();
        $user->givePermissionTo('barcodes.print');
        $user->givePermissionTo('products.access');
        $role = Role::firstOrCreate(['name' => 'barcode-test-role', 'guard_name' => 'web']);
        $user->settings()->attach($this->settingA->id, ['role_id' => $role->id]);

        $product = $this->makeProduct(['product_name' => 'Guarded Product', 'barcode' => '1234567890123']);
        $this->setPrice($product, $this->settingA, 10000);
        $this->setPrice($product, $otherSetting, 99000);

        session(['setting_id' => $this->settingA->id]);

        // 1. Initial mount under authorized setting
        $componentAuthorized = Livewire::actingAs($user)
            ->test(\Modules\Product\Livewire\BarcodeProductSearch::class, [
                'selectedSettingId' => $this->settingA->id,
            ])
            ->set('query', 'Guarded')
            ->assertSee('Guarded Product')
            ->assertSee(format_currency(10000), false);

        $this->assertCount(1, $componentAuthorized->get('search_results'));

        // 2. Mount under unauthorized setting (query retained)
        $componentUnauthorized = Livewire::actingAs($user)
            ->test(\Modules\Product\Livewire\BarcodeProductSearch::class, [
                'selectedSettingId' => $otherSetting->id,
            ])
            ->set('query', 'Guarded')
            ->assertDontSee(format_currency(99000), false)
            ->assertDontSee(format_currency(10000), false)
            ->assertSee('Perusahaan yang dipilih tidak dapat diakses.')
            ->assertSet('search_results', collect());

        $this->assertSame('Guarded', $componentUnauthorized->get('query'));
    }

    public function test_search_suggestions_show_unavailable_price_when_price_is_missing_or_null(): void
    {
        $this->actingAsOperator();

        $noPriceProd = $this->makeProduct(['product_name' => 'No Price Widget', 'barcode' => '1111111111111', 'product_price' => 99000]);
        $nullPriceProd = $this->makeProduct(['product_name' => 'Null Price Widget', 'barcode' => '2222222222222', 'product_price' => 88000]);
        $this->setPrice($nullPriceProd, $this->settingA, null);

        session(['setting_id' => $this->settingA->id]);

        $component = Livewire::actingAs($this->operator)
            ->test(\Modules\Product\Livewire\BarcodeProductSearch::class, [
                'selectedSettingId' => $this->settingA->id,
            ])
            ->set('query', 'Price Widget');

        $component->assertSee('No Price Widget')
            ->assertSee('Null Price Widget')
            ->assertSee('Harga tidak tersedia')
            ->assertDontSee(format_currency(99000), false)
            ->assertDontSee(format_currency(88000), false);

        $results = $component->get('search_results');
        $this->assertCount(2, $results);
        $this->assertNull($results[0]['sale_price']);
        $this->assertNull($results[1]['sale_price']);
    }

    public function test_search_does_not_expose_price_for_unauthorized_business(): void
    {
        $otherSetting = Setting::factory()->create(['company_name' => 'Secret Business']);

        $user = User::factory()->create();
        $user->givePermissionTo('barcodes.print');
        $user->givePermissionTo('products.access');
        $role = Role::firstOrCreate(['name' => 'barcode-test-role', 'guard_name' => 'web']);
        $user->settings()->attach($this->settingA->id, ['role_id' => $role->id]);

        $product = $this->makeProduct(['product_name' => 'Secret Product', 'barcode' => '9999999999999']);
        $this->setPrice($product, $otherSetting, 500000);

        session(['setting_id' => $this->settingA->id]);

        Livewire::actingAs($user)
            ->test(\Modules\Product\Livewire\BarcodeProductSearch::class, [
                'selectedSettingId' => $otherSetting->id,
            ])
            ->set('query', 'Secret')
            ->assertDontSee(format_currency(500000), false)
            ->assertSet('search_results', collect());
    }

    public function test_search_input_has_guidance_for_name_sku_and_barcode(): void
    {
        $this->actingAsOperator();

        session(['setting_id' => $this->settingA->id]);

        Livewire::actingAs($this->operator)
            ->test(\Modules\Product\Livewire\BarcodeProductSearch::class, [
                'selectedSettingId' => $this->settingA->id,
            ])
            ->assertSeeHtml('placeholder="Ketik nama, SKU, atau scan barcode produk..."');
    }

    public function test_barcode_search_finds_product_by_partial_barcode_match(): void
    {
        $this->actingAsOperator();

        $product = $this->makeProduct(['product_name' => 'Widget Plus', 'barcode' => '1234567890123']);

        $results = Product::query()
            ->globalSearch('123456')
            ->get(['id', 'product_name', 'product_code', 'product_unit']);

        $this->assertNotEmpty($results);
        $this->assertSame($product->id, $results[0]->id);
    }

    public function test_barcode_search_component_can_select_product_by_barcode_from_results(): void
    {
        $this->actingAsOperator();

        $product = $this->makeProduct(['product_name' => 'Widget Plus', 'barcode' => '1234567890123']);

        $component = Livewire::actingAs($this->operator)
            ->test(\Modules\Product\Livewire\BarcodeProductSearch::class);

        $component->call('selectProduct', [
            'id' => (int) $product->id,
            'product_name' => 'WIDGET PLUS',
            'product_code' => $product->product_code,
            'product_unit' => $product->product_unit,
        ])
        ->assertDispatched('productSelected', [
            'id' => $product->id,
            'product_name' => 'WIDGET PLUS',
            'product_code' => $product->product_code,
            'product_unit' => $product->product_unit,
        ]);

        $this->assertEmpty($component->get('query'));
    }

    public function test_enter_on_exact_barcode_match_adds_product_and_clears_input(): void
    {
        $this->actingAsOperator();

        $product = $this->makeProduct(['product_name' => 'Widget Plus', 'barcode' => '1234567890123']);

        Livewire::actingAs($this->operator)
            ->test(\Modules\Product\Livewire\BarcodeProductSearch::class)
            ->set('query', '1234567890123')
            ->call('handleEnter')
            ->assertDispatched('productSelected')
            ->assertSet('query', '');
    }

    public function test_enter_on_exact_barcode_with_workspace_increments_existing_batch_row(): void
    {
        $this->actingAsOperator();

        $product = $this->makeProduct(['product_name' => 'Widget Plus', 'barcode' => '1234567890123']);
        $this->setPrice($product, $this->settingA, 12500);

        $component = Livewire::actingAs($this->operator)
            ->test(BarcodeBatchWorkspace::class)
            ->call('addProduct', ['id' => $product->id]);

        $this->assertSame(1, $component->get('rows')[0]['quantity']);

        $component->dispatch('productSelected', [
            'id' => $product->id,
            'product_name' => 'WIDGET PLUS',
            'product_code' => $product->product_code,
            'product_unit' => $product->product_unit,
        ]);

        $this->assertSame(2, $component->get('rows')[0]['quantity']);
    }

    public function test_enter_on_unmatched_barcode_shows_results_without_adding_product(): void
    {
        $this->actingAsOperator();

        $product = $this->makeProduct(['product_name' => 'Widget Plus', 'barcode' => '1234567890123']);

        Livewire::actingAs($this->operator)
            ->test(\Modules\Product\Livewire\BarcodeProductSearch::class)
            ->set('query', '9999999999999')
            ->call('handleEnter')
            ->assertNotDispatched('productSelected')
            ->assertSee('Produk tidak ditemukan');
    }

    public function test_enter_on_conversion_barcode_only_does_not_add_product(): void
    {
        $this->actingAsOperator();

        $product = $this->makeProduct(['product_name' => 'Widget Box', 'barcode' => '1111111111111']);
        $unit = Unit::firstOrCreate(['name' => 'Box', 'short_name' => 'BOX']);

        $conversion = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $this->unit->id,
            'conversion_factor' => 10,
            'barcode' => '2222222222222',
        ]);

        Livewire::actingAs($this->operator)
            ->test(\Modules\Product\Livewire\BarcodeProductSearch::class)
            ->set('query', '2222222222222')
            ->call('handleEnter')
            ->assertNotDispatched('productSelected')
            ->assertSee('Produk tidak ditemukan');
    }

    public function test_embedded_barcode_search_in_workspace_increments_row_on_exact_primary_barcode_enter(): void
    {
        $this->actingAsOperator();

        $product = $this->makeProduct(['product_name' => 'Scanner Test Product', 'barcode' => '5555555555555']);
        $this->setPrice($product, $this->settingA, 25000);

        $workspace = Livewire::actingAs($this->operator)
            ->test(BarcodeBatchWorkspace::class);

        $workspace->call('addProduct', ['id' => $product->id]);
        $this->assertSame(1, $workspace->get('rows')[0]['quantity']);
        $this->assertSame($product->id, $workspace->get('rows')[0]['product_id']);

        $search = Livewire::actingAs($this->operator)
            ->test(\Modules\Product\Livewire\BarcodeProductSearch::class);

        $search->set('query', '5555555555555')
            ->call('handleEnter');

        $search->assertDispatched('productSelected')
            ->assertSet('query', '');

        $workspace->dispatch('productSelected', [
            'id' => $product->id,
            'product_name' => 'SCANNER TEST PRODUCT',
            'product_code' => $product->product_code,
            'product_unit' => $product->product_unit,
        ]);

        $this->assertSame(2, $workspace->get('rows')[0]['quantity']);
        $this->assertSame(1, count($workspace->get('rows')));
    }
}
