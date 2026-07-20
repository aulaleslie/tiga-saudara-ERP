<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
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
class POSWalkInCustomerSelectionTest extends TestCase
{
    use RefreshDatabase;

    private int $terminalSequence = 1;

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

    public function test_customer_search_is_global_and_supports_name_or_phone(): void
    {
        $setting = $this->createSetting('BIZ POS CUSTOMER SEARCH');
        $otherSetting = $this->createSetting('BIZ POS CUSTOMER OTHER');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'POS CUSTOMER SEARCH');

        $allowedByName = $this->createCustomer($setting, 'Walk In Utama', '08123450001');
        $allowedByPhone = $this->createCustomer($setting, 'Pelanggan Lama', '08177771234');
        $crossSetting = $this->createCustomer($otherSetting, 'Walk In Lintas', '08123450001');

        $nameResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.customers.search', ['q' => 'walk in']));

        $nameResponse->assertOk();

        $nameResultIds = collect($nameResponse->json('results'))->pluck('id')->all();
        $this->assertContains($allowedByName->id, $nameResultIds);
        $this->assertContains($crossSetting->id, $nameResultIds);

        $phoneResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.customers.search', ['q' => '1234']));

        $phoneResponse->assertOk();

        $resultIds = collect($phoneResponse->json('results'))->pluck('id')->all();
        $this->assertContains($allowedByName->id, $resultIds);
        $this->assertContains($allowedByPhone->id, $resultIds);
        $this->assertContains($crossSetting->id, $resultIds);
    }

    public function test_selecting_valid_customer_sets_selected_resolution_source(): void
    {
        $setting = $this->createSetting('BIZ POS CUSTOMER SELECT');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'POS CUSTOMER SELECT');

        $defaultCustomer = $this->createCustomer($setting, 'Walk In Default', '08110000001');
        $selectedCustomer = $this->createCustomer($setting, 'Pelanggan Prioritas', '08110000002');

        $setting->update(['pos_walk_in_customer_id' => $defaultCustomer->id]);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $selectedCustomer->id,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.customer.selected_customer_id', $selectedCustomer->id)
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', $selectedCustomer->id)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'selected');
    }

    public function test_clearing_customer_selection_sets_customer_to_null(): void
    {
        $setting = $this->createSetting('BIZ POS CUSTOMER CLEAR');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'POS CUSTOMER CLEAR');

        $selectedCustomer = $this->createCustomer($setting, 'Pelanggan Tetap', '08120000002');

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $selectedCustomer->id,
            ])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.customer.selected_customer_id', null)
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', null)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'none');
    }

    public function test_cross_setting_customer_selection_is_allowed(): void
    {
        $setting = $this->createSetting('BIZ POS CUSTOMER STRICT');
        $otherSetting = $this->createSetting('BIZ POS CUSTOMER STRICT OTHER');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'POS CUSTOMER STRICT');

        $otherCustomer = $this->createCustomer($otherSetting, 'Pelanggan Asing', '08990000001');

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $otherCustomer->id,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.customer.selected_customer_id', $otherCustomer->id)
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', $otherCustomer->id)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'selected');
    }

    public function test_customer_selection_changes_do_not_reprice_non_tier_products(): void
    {
        $setting = $this->createSetting('BIZ POS CUSTOMER TOTALS');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'POS CUSTOMER TOTALS');

        $customer1 = $this->createCustomer($setting, 'Pelanggan Satu', '08880000001');
        $customer2 = $this->createCustomer($setting, 'Pelanggan Dua', '08880000002');

        // Use non-tier product: pricing should remain stable across customer change
        $product = $this->createStockedProduct($setting, $location, 'SKU-CUST-001', 'Produk A', 12345, $cashier->id);

        $baseline = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 2,
            ])
            ->assertOk()
            ->json('cart_snapshot.totals.grand_total');

        $afterSelect = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer1->id,
            ])
            ->assertOk()
            ->json('cart_snapshot.totals.grand_total');

        $afterSwitch = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer2->id,
            ])
            ->assertOk()
            ->json('cart_snapshot.totals.grand_total');

        // Non-tier customers: totals should remain unchanged (base price applies)
        $this->assertSame($baseline, $afterSelect);
        $this->assertSame($baseline, $afterSwitch);
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
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
        ]);
    }

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    /**
     * @return array{0: User, 1: Location, 2: PosSession}
     */
    private function createCashierAndOpenSession(Setting $setting, string $roleSuffix): array
    {
        $cashier = $this->createUserForSetting(
            $setting,
            $roleSuffix . ' CASHIER',
            ['pos.access', 'pos.sell', 'pos.sessions.open']
        );

        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);

        $session = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);

        return [$cashier, $location, $session];
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'POS CUST LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-CUST-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Customer Terminal ' . $sequence,
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
        ]);

        return $terminal;
    }

    private function createCustomer(Setting $setting, string $name, string $phone): Customer
    {
        return Customer::create([
            'setting_id' => $setting->id,
            'contact_name' => $name,
            'customer_name' => $name,
            'customer_phone' => $phone,
            'customer_email' => strtolower(str_replace(' ', '.', $name)) . '.' . $setting->id . '@example.com',
            'address' => 'Address',
            'city' => 'City',
            'country' => 'Country',
        ]);
    }

    private function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        string $name,
        float $salePrice,
        int $createdBy
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => 'POS-CUST-CAT-' . $setting->id],
            [
                'category_name' => 'POS Customer Category ' . $setting->id,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'Piece',
            'short_name' => 'PCS',
        ]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $name,
            'product_code' => $code,
            'barcode' => $code . '-BC',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => $salePrice,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $salePrice,
            'tier_1_price' => null,
            'tier_2_price' => null,
        ]);

        return $product->fresh();
    }
}
