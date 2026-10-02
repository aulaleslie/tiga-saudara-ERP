<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSCustomerTierRepricingTest extends TestCase
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

    public function test_selecting_tier_customer_applies_tier_price(): void
    {
        $context = $this->createCheckoutContext('POS TIER SELECT');
        
        $tierCustomer = Customer::factory()->create(['setting_id' => $context['setting']->id, 'tier' => 'WHOLESALER']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-TIER-001', 100000, 50000);
        
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $snapshot1 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals(100000, $snapshot1['lines'][0]['unit_price'], 'Base price should be sale_price initially');

        // Select tier customer
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $tierCustomer->id,
            ])
            ->assertOk();

        $snapshot2 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals(50000, $snapshot2['lines'][0]['unit_price'], 'Tier price should apply after customer selection');
    }

    public function test_switching_tier_customer_updates_prices(): void
    {
        $context = $this->createCheckoutContext('POS TIER SWITCH');
        
        $tierCustomer1 = Customer::factory()->create(['setting_id' => $context['setting']->id, 'tier' => 'WHOLESALER']);
        $tierCustomer2 = Customer::factory()->create(['setting_id' => $context['setting']->id, 'tier' => 'RESELLER']);
        
        // Setup different tier prices
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-SWITCH', 100000, 50000);
        // We simulate tier 2 by updating tier_2_price field
        ProductPrice::where('product_id', $product->id)->where('setting_id', $context['setting']->id)
            ->update(['tier_2_price' => 40000]);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        // Select tier customer 1
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $tierCustomer1->id])
            ->assertOk();
        $snapshot2 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals(50000, $snapshot2['lines'][0]['unit_price']);

        // Switch to tier customer 2
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $tierCustomer2->id])
            ->assertOk();
        $snapshot3 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals(40000, $snapshot3['lines'][0]['unit_price']);
    }

    public function test_clearing_customer_reverts_to_base_price(): void
    {
        $context = $this->createCheckoutContext('POS TIER CLEAR');
        
        $tierCustomer = Customer::factory()->create(['setting_id' => $context['setting']->id, 'tier' => 'WHOLESALER']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-CLEAR', 100000, 50000);
        
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        // Select tier customer
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $tierCustomer->id])
            ->assertOk();
        $snapshot2 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals(50000, $snapshot2['lines'][0]['unit_price']);

        // Clear customer
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => null])
            ->assertOk();
        $snapshot3 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals(100000, $snapshot3['lines'][0]['unit_price'], 'Base price should be restored');
    }

    public function test_multi_line_repricing_on_tier_selection(): void
    {
        $context = $this->createCheckoutContext('POS TIER MULTI');
        
        $tierCustomer = Customer::factory()->create(['setting_id' => $context['setting']->id, 'tier' => 'WHOLESALER']);
        $product1 = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-M1', 100000, 50000);
        $product2 = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-M2', 200000, 150000);

        $this->addCartLine($context['cashier'], $context['setting'], $product1->id, 2);
        $this->addCartLine($context['cashier'], $context['setting'], $product2->id, 1);
        $snapshot1 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals(100000, $snapshot1['lines'][0]['unit_price']);
        $this->assertEquals(200000, $snapshot1['lines'][1]['unit_price']);

        // Select tier customer
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $tierCustomer->id])
            ->assertOk();

        $snapshot2 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals(50000, $snapshot2['lines'][0]['unit_price']);
        $this->assertEquals(150000, $snapshot2['lines'][1]['unit_price']);
        $this->assertEquals(250000, $snapshot2['totals']['subtotal'], 'Subtotal should reflect tier prices'); // 2*50 + 150 = 250
    }

    public function test_tier_repricing_does_not_exist_for_non_tier_customer(): void
    {
        $context = $this->createCheckoutContext('POS TIER NON');
        
        $nonTierCustomer = Customer::factory()->create(['setting_id' => $context['setting']->id, 'tier' => 'WHOLESALER']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-NOTIER', 100000, null);
        
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $snapshot1 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals(100000, $snapshot1['lines'][0]['unit_price']);

        // Select non-tier customer
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $nonTierCustomer->id])
            ->assertOk();

        $snapshot2 = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals(100000, $snapshot2['lines'][0]['unit_price'], 'Price should remain base for non-tier customer');
    }

    public function test_bundle_line_price_includes_base_or_tier_price_plus_bundle_price(): void
    {
        $context = $this->createCheckoutContext('POS TIER BUNDLE');

        $tierCustomer = Customer::factory()->create(['setting_id' => $context['setting']->id, 'tier' => 'WHOLESALER']);
        $parent = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-BUNDLE-PARENT', 100000, 50000);
        $child = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-BUNDLE-CHILD', 20000, null);

        $bundle = ProductBundle::query()->create([
            'setting_id' => $context['setting']->id,
            'parent_product_id' => $parent->id,
            'name' => 'Bundle Parent + Child',
            'bundle_sale_price' => 125000,
            'price' => 15000, // legacy
        ]);

        ProductBundleItem::query()->create([
            'bundle_id' => $bundle->id,
            'product_id' => $child->id,
            'quantity' => 1,
        ]);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $parent->id,
                'qty' => 1,
                'bundle_id' => $bundle->id,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.unit_price', 125000)
            ->assertJsonPath('cart_snapshot.lines.0.bundle_price', 15000);

        $this->selectCustomerInCart($context['cashier'], $context['setting'], $tierCustomer);

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals(125000, $snapshot['lines'][0]['unit_price'], 'Bundle line price should use authoritative bundle_sale_price and bypass tier repricing.');
        $this->assertEquals(15000, $snapshot['lines'][0]['bundle_price'], 'Legacy bundle price should be preserved in metadata.');
    }

    public function test_adding_ordinary_product_when_wholesaler_selected_applies_tier1_price_and_tags_tier(): void
    {
        $context = $this->createCheckoutContext('POS TIER FIRST WHOLESALE');

        $wholesalerCustomer = Customer::factory()->create([
            'setting_id' => $context['setting']->id,
            'tier' => 'WHOLESALER',
        ]);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-FIRST-WS', 100000, 75000);

        // Select WHOLESALER customer on empty cart first
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $wholesalerCustomer);

        // Add ordinary product
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertCount(1, $snapshot['lines']);
        $line = reset($snapshot['lines']);
        $this->assertEquals(75000, $line['unit_price'], 'Initial unit price should match tier 1 price for WHOLESALER');
        $this->assertEquals('TIER', $line['price_source'], 'Price source should be TIER when tier price is applied');
    }

    public function test_adding_ordinary_product_when_reseller_selected_applies_tier2_price_and_tags_tier(): void
    {
        $context = $this->createCheckoutContext('POS TIER FIRST RESELLER');

        $resellerCustomer = Customer::factory()->create([
            'setting_id' => $context['setting']->id,
            'tier' => 'RESELLER',
        ]);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-FIRST-RS', 100000, 75000);
        ProductPrice::where('product_id', $product->id)->where('setting_id', $context['setting']->id)
            ->update(['tier_2_price' => 65000]);

        // Select RESELLER customer on empty cart first
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $resellerCustomer);

        // Add ordinary product
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertCount(1, $snapshot['lines']);
        $line = reset($snapshot['lines']);
        $this->assertEquals(65000, $line['unit_price'], 'Initial unit price should match tier 2 price for RESELLER');
        $this->assertEquals('TIER', $line['price_source'], 'Price source should be TIER when tier price is applied');
    }

    public function test_adding_product_falls_back_to_base_price_when_tier_price_is_zero_or_null(): void
    {
        $context = $this->createCheckoutContext('POS TIER ZERO FALLBACK');

        $wholesalerCustomer = Customer::factory()->create([
            'setting_id' => $context['setting']->id,
            'tier' => 'WHOLESALER',
        ]);
        // tier_1_price is null/0
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-TIER-ZERO', 100000, 0);

        $this->selectCustomerInCart($context['cashier'], $context['setting'], $wholesalerCustomer);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $line = reset($snapshot['lines']);
        $this->assertEquals(100000, $line['unit_price'], 'Should fall back to base sale_price when tier price is 0');
        $this->assertEquals('BASE', $line['price_source'], 'Price source should be BASE when tier price was not applied');
    }

    public function test_adding_product_for_customer_without_tier_uses_base_price(): void
    {
        $context = $this->createCheckoutContext('POS NO TIER CUSTOMER');

        $regularCustomer = Customer::factory()->create([
            'setting_id' => $context['setting']->id,
            'tier' => null,
        ]);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-NO-TIER-CUST', 100000, 80000);

        $this->selectCustomerInCart($context['cashier'], $context['setting'], $regularCustomer);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $line = reset($snapshot['lines']);
        $this->assertEquals(100000, $line['unit_price'], 'Customer without tier should get base sale price');
        $this->assertEquals('BASE', $line['price_source']);
    }

    public function test_conversion_unit_on_ordinary_product_uses_tier_price_per_base_unit_for_tier_customer(): void
    {
        $context = $this->createCheckoutContext('POS CONV TIER ORDINARY');

        $wholesalerCustomer = Customer::factory()->create([
            'setting_id' => $context['setting']->id,
            'tier' => 'WHOLESALER',
        ]);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-CONV-ORD', 10000, 8000);

        $unit = Unit::firstOrCreate(['name' => 'PackConv', 'short_name' => 'PCK']);
        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $product->base_unit_id,
            'conversion_factor' => 5,
            'barcode' => 'CONV-ORD-001',
        ]);

        $this->selectCustomerInCart($context['cashier'], $context['setting'], $wholesalerCustomer);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 2, // 2 packs = 10 base units
                'conversion_id' => $conversion->id,
            ])
            ->assertOk();

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $line = reset($snapshot['lines']);
        $this->assertEquals(10, $line['qty'], 'Qty should be multiplied by conversion factor (2 * 5 = 10)');
        $this->assertEquals(8000, $line['unit_price'], 'Unit price should be tier price per base unit');
        $this->assertEquals('TIER', $line['price_source']);
    }

    public function test_repeated_add_merges_into_one_line_at_tier_price(): void
    {
        $context = $this->createCheckoutContext('POS TIER MERGE');

        $wholesalerCustomer = Customer::factory()->create([
            'setting_id' => $context['setting']->id,
            'tier' => 'WHOLESALER',
        ]);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-TIER-MRG', 100000, 70000);

        $this->selectCustomerInCart($context['cashier'], $context['setting'], $wholesalerCustomer);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertCount(1, $snapshot['lines'], 'Both additions should merge into a single cart line');
        $line = reset($snapshot['lines']);
        $this->assertEquals(3, $line['qty']);
        $this->assertEquals(70000, $line['unit_price']);
        $this->assertEquals('TIER', $line['price_source']);
    }

    public function test_bundle_initial_add_unaffected_when_tier_customer_selected(): void
    {
        $context = $this->createCheckoutContext('POS TIER BUNDLE INITIAL');

        $wholesalerCustomer = Customer::factory()->create([
            'setting_id' => $context['setting']->id,
            'tier' => 'WHOLESALER',
        ]);
        $parent = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-BNDL-P2', 100000, 50000);
        $child = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-BNDL-C2', 20000, null);

        $bundle = ProductBundle::query()->create([
            'setting_id' => $context['setting']->id,
            'parent_product_id' => $parent->id,
            'name' => 'Bundle Parent + Child 2',
            'bundle_sale_price' => 130000,
            'price' => 20000,
        ]);

        ProductBundleItem::query()->create([
            'bundle_id' => $bundle->id,
            'product_id' => $child->id,
            'quantity' => 1,
        ]);

        $this->selectCustomerInCart($context['cashier'], $context['setting'], $wholesalerCustomer);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $parent->id,
                'qty' => 1,
                'bundle_id' => $bundle->id,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.unit_price', 130000)
            ->assertJsonPath('cart_snapshot.lines.0.bundle_price', 20000);

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $this->assertEquals(130000, $snapshot['lines'][0]['unit_price']);
        $this->assertEquals('BUNDLE', $snapshot['lines'][0]['price_source']);
    }

    // ==== HELPER METHODS ====

    private function createCheckoutContext(string $name): array
    {
        $setting = $this->createSetting($name);
        $cashier = $this->createUserForSetting($setting, $name . '-cashier', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting);
        
        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Cash Account ' . $name,
            'account_number' => 'CASH-' . $name,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $method = PaymentMethod::create([
            'name' => 'Cash ' . $name,
            'coa_id' => $coaId,
            'is_cash' => true,
        ]);
        
        \Modules\Setting\Entities\SettingPosPaymentMethod::create([
            'setting_id' => $setting->id,
            'payment_method_id' => $method->id,
            'is_enabled' => true,
        ]);

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
            'company_email' => 'pos.tier.' . $suffix . '@example.com',
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
            'name' => 'POS TIER LOC ' . $index,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-TIER-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Tier Terminal ' . $index,
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

    private function createStockedProduct(Setting $setting, Location $location, string $code, float $salePrice, ?float $tierPrice = null): Product
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
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        ProductPrice::query()->updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
            'tier_1_price' => $tierPrice,
        ]);

        return $product;
    }

    private function applyTierPrice(Product $product, Setting $setting, Customer $customer, float $tierPrice): void
    {
        ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $setting->id)
            ->update(['tier_1_price' => $tierPrice]);
    }

        private function selectCustomerInCart(User $cashier, Setting $setting, Customer $customer): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer->id,
            ])
            ->assertOk();
    }

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty, ?int $bundleId = null): void
    {
        $payload = [
            'product_id' => $productId,
            'qty' => $qty,
        ];

        if ($bundleId !== null) {
            $payload['bundle_id'] = $bundleId;
        }

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), $payload)
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
}
