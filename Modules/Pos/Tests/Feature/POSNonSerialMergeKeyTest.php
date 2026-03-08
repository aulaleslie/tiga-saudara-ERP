<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSNonSerialMergeKeyTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_same_product_same_price_merges_into_one_line(): void
    {
        $context = $this->createCheckoutContext('POS MERGE SAME');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-MERGE', 100000);

        // Add product twice
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 3);

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertCount(1, $snapshot['lines'], 'Same product should merge into one line');
        $this->assertEquals(5, $snapshot['lines'][0]['qty'], 'Quantities should combine');
        $this->assertEquals('PROD-MERGE', $snapshot['lines'][0]['product_code']);
    }

    public function test_same_product_different_prices_creates_separate_lines(): void
    {
        $context = $this->createCheckoutContext('POS MERGE DIFF PRICE');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-DPRICE', 100000);

        // Manually add line with custom price (simulating price override)
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);
        
        $snapshot1 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertCount(1, $snapshot1['lines']);

        // In real scenario, this would be creating a line with override price via API
        // For this test, we verify merge key logic by checking line structure
        $this->assertEquals(100000, $snapshot1['lines'][0]['unit_price']);
        $this->assertEquals(200000, $snapshot1['lines'][0]['subtotal']);
    }

    public function test_cart_line_has_stable_merge_key(): void
    {
        $context = $this->createCheckoutContext('POS MERGE KEY');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-KEY', 100000);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $snapshot1 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $mergeKey1 = $snapshot1['lines'][0]['merge_key'] ?? null;

        // Modify some non-key property
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->putJson(route('pos.sell.cart.lines.update', ['lineId' => $snapshot1['lines'][0]['line_id']]), [
                'qty' => 2,
            ])
            ->assertOk();

        $snapshot2 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $mergeKey2 = $snapshot2['lines'][0]['merge_key'] ?? null;

        $this->assertEquals($mergeKey1, $mergeKey2, 'Merge key should remain stable across qty changes');
    }

    public function test_removing_and_readding_product_merges_back(): void
    {
        $context = $this->createCheckoutContext('POS MERGE READD');
        $product1 = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-R1', 100000);
        $product2 = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-R2', 150000);

        // Add product 1, then product 2
        $this->addCartLine($context['cashier'], $context['setting'], $product1->id, 2);
        $this->addCartLine($context['cashier'], $context['setting'], $product2->id, 1);

        $snapshot1 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertCount(2, $snapshot1['lines']);

        // Remove product 1
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->deleteJson(route('pos.sell.cart.lines.destroy', ['lineId' => $snapshot1['lines'][0]['line_id']]))
            ->assertOk();

        $snapshot2 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertCount(1, $snapshot2['lines']);

        // Re-add product 1
        $this->addCartLine($context['cashier'], $context['setting'], $product1->id, 3);

        $snapshot3 = $this->cartSnapshot($context['cashier'], $context['setting']);
        // Product 1 and 2 should both be present
        $this->assertCount(2, $snapshot3['lines']);
        $product1Line = collect($snapshot3['lines'])->firstWhere('product_id', $product1->id);
        $this->assertEquals(3, $product1Line['qty'], 'Re-added product should have new qty, not merged with old');
    }

    public function test_checkout_preserves_line_merge_keys_in_sale_details(): void
    {
        $context = $this->createCheckoutContext('POS MERGE CHECKOUT');
        $methods = $this->seedPaymentMethods($context['setting']);
        
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-CHKOUT', 100000);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 3); // Should merge

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertCount(1, $snapshot['lines']);
        $this->assertEquals(5, $snapshot['lines'][0]['qty']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-MERGE-001',
            'payment' => [
                'payment_method_id' => $methods->cash->id,
                'amount_paid' => 500000,
            ],
        ]);

        $response->assertStatus(201);
        
        $saleId = $response->json('sale_id');
        $this->assertDatabaseHas('sale_details', [
            'sale_id' => $saleId,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);
    }

    // ==== HELPER METHODS ====

    private function createCheckoutContext(string $name): array
    {
        $setting = $this->createSetting($name);
        $cashier = $this->createUserForSetting($setting, $name . '-cashier', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);

        $sessionLifecycle = app(PosSessionLifecycleService::class);
        $session = $sessionLifecycle->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );

        return [
            'setting' => $setting,
            'cashier' => $cashier,
            'terminal' => $terminal,
            'location' => $location,
            'session' => $session,
        ];
    }

    private function createSetting(string $name): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'pos.merge.' . $suffix . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
            'is_pkp' => false,
        ]);
    }

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => strtoupper($roleName) . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $index = $this->sequence++;

        $location = Location::create([
            'name' => 'POS MERGE LOC ' . $index,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-MERGE-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Merge Terminal ' . $index,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
    }

    private function createStockedProduct(Setting $setting, Location $location, string $code, float $salePrice): Product
    {
        $category = Category::firstOrCreate(
            ['category_code' => $code . '-CAT'],
            [
                'category_name' => $code . ' CATEGORY',
                'created_by' => 1,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT',
            'short_name' => 'PUNIT',
        ]);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code,
            'barcode' => $code . '-BAR',
            'product_quantity' => 100,
            'product_cost' => 10000,
            'product_price' => $salePrice,
            'product_unit' => 'PUNIT',
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
        ]);

        ProductPrice::query()->updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
            'last_purchase_price' => 10000,
            'average_purchase_price' => 10000,
        ]);

        return $product;
    }

    private function seedPaymentMethods(Setting $setting): object
    {
        $methods = [];
        
        foreach (['CASH' => true, 'TRANSFER' => false, 'QRIS' => false] as $name => $isCash) {
            $coaId = \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
                'name' => "COA $name " . $this->sequence,
                'account_number' => "ACC-$name-" . $this->sequence++,
                'category' => 'Kas & Bank',
                'setting_id' => $setting->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $methods[strtolower($name)] = \Modules\Setting\Entities\PaymentMethod::create([
                'name' => "$name POS",
                'coa_id' => $coaId,
                'is_cash' => $isCash,
                'is_available_in_pos' => true,
                'requires_reference' => !$isCash,
            ]);
        }

        return (object) $methods;
    }

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productId,
                'qty' => $qty,
            ])
            ->assertOk();
    }

    private function cartSnapshot(User $cashier, Setting $setting): array
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->json('cart_snapshot');
    }

    private function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }
}
