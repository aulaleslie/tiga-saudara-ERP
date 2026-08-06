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
}
