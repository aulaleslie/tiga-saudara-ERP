<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
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
class POSSerialPreservationAndSuperAdminBypassTest extends TestCase
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
    }

    /**
     * Test 1.2: Assigned serials are preserved when qty increases
     */
    public function test_assigned_serials_preserved_when_qty_increases(): void
    {
        $context = $this->createCheckoutContext('QTY INCREASE PRESERVE');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-QTY-UP', 100000, true);

        $sn1 = $this->createSerialNumber($product, $context['location'], 'SN-QTY-UP-1');
        $sn2 = $this->createSerialNumber($product, $context['location'], 'SN-QTY-UP-2');
        $sn3 = $this->createSerialNumber($product, $context['location'], 'SN-QTY-UP-3');

        // Add line with qty 2
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign 2 serials
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-QTY-UP-1', 'SN-QTY-UP-2'],
            ])
            ->assertOk();

        // Increase qty to 3
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), [
                'qty' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.qty', 3)
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-QTY-UP-1', 'SN-QTY-UP-2']);
    }

    /**
     * Test 1.3: Assigned serials are preserved when qty decreases
     */
    public function test_assigned_serials_preserved_when_qty_decreases(): void
    {
        $context = $this->createCheckoutContext('QTY DECREASE PRESERVE');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-QTY-DOWN', 100000, true);

        $this->createSerialNumber($product, $context['location'], 'SN-QTY-DOWN-1');
        $this->createSerialNumber($product, $context['location'], 'SN-QTY-DOWN-2');
        $this->createSerialNumber($product, $context['location'], 'SN-QTY-DOWN-3');

        // Add line with qty 3
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 3);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign 3 serials
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-QTY-DOWN-1', 'SN-QTY-DOWN-2', 'SN-QTY-DOWN-3'],
            ])
            ->assertOk();

        // Decrease qty to 2 - serials should be preserved
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), [
                'qty' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.qty', 2)
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-QTY-DOWN-1', 'SN-QTY-DOWN-2', 'SN-QTY-DOWN-3']);
    }

    /**
     * Test 1.4: Qty can be reduced below serial count, but checkout validates mismatch
     */
    public function test_qty_can_be_reduced_below_serial_count(): void
    {
        $context = $this->createCheckoutContext('QTY GUARD');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-GUARD', 100000, true);

        $this->createSerialNumber($product, $context['location'], 'SN-GUARD-1');
        $this->createSerialNumber($product, $context['location'], 'SN-GUARD-2');

        // Add line with qty 2
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 2);
        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign 2 serials
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-GUARD-1', 'SN-GUARD-2'],
            ])
            ->assertOk();

        // Reduce qty to 1 - should succeed in cart, but qty < serial count mismatch will be caught at checkout
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), [
                'qty' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.qty', 1)
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-GUARD-1', 'SN-GUARD-2']);
    }

    /**
     * Test 2.4: Super Admin can reduce qty without approval token
     */
    public function test_super_admin_can_reduce_qty_without_approval(): void
    {
        $context = $this->createCheckoutContext('SUPER ADMIN QTY');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-SUPER-QTY', 100000, false);

        // Create Super Admin user
        $superAdmin = $this->createSuperAdminUser($context['setting']);

        // Add line and reduce qty without approval
        $this->addCartLine($superAdmin, $context['setting'], $product->id, 5);
        $snapshot = $this->cartSnapshot($superAdmin, $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Super Admin should be able to reduce qty without token
        $this->actingAs($superAdmin)
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), [
                'qty' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.qty', 3);
    }

    /**
     * Test 2.5: Super Admin without pos.cart.line.reduce permission can still reduce qty
     */
    public function test_super_admin_without_permission_can_reduce_qty(): void
    {
        $context = $this->createCheckoutContext('SUPER ADMIN NO PERM');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-SUPER-NOPERM', 100000, false);

        // Create Super Admin user
        $superAdmin = $this->createSuperAdminUser($context['setting']);

        $this->addCartLine($superAdmin, $context['setting'], $product->id, 5);
        $snapshot = $this->cartSnapshot($superAdmin, $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Should still be able to reduce because Super Admin bypasses permission checks
        $this->actingAs($superAdmin)
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), [
                'qty' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.qty', 2);
    }

    /**
     * Test 2.6: Non-Super-Admin still requires approval when lacking permission
     */
    public function test_non_super_admin_requires_approval_without_permission(): void
    {
        $context = $this->createCheckoutContext('NON-SUPER APPROVAL');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-NONADMIN', 100000, false);

        // Create Cashier without pos.cart.line.reduce permission
        $cashier = $this->createUserForSetting($context['setting'], 'Cashier', ['pos.sell']);

        $this->addCartLine($cashier, $context['setting'], $product->id, 5);
        $snapshot = $this->cartSnapshot($cashier, $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Should fail without approval token
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), [
                'qty' => 3,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'APPROVAL_REQUIRED');
    }

    /**
     * Test 4.1: Super Admin reduces qty with serials assigned → no approval required
     */
    public function test_super_admin_qty_reduce_with_serials_no_approval(): void
    {
        $context = $this->createCheckoutContext('SUPER SERIAL QTY');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-SUPER-SERIAL', 100000, true);

        $this->createSerialNumber($product, $context['location'], 'SN-SUPER-1');
        $this->createSerialNumber($product, $context['location'], 'SN-SUPER-2');

        // Create Super Admin
        $superAdmin = $this->createSuperAdminUser($context['setting']);

        $this->addCartLine($superAdmin, $context['setting'], $product->id, 2);
        $snapshot = $this->cartSnapshot($superAdmin, $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign serials
        $this->actingAs($superAdmin)
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-SUPER-1', 'SN-SUPER-2'],
            ])
            ->assertOk();

        // Super Admin reduces qty - should succeed without approval (mismatch caught at checkout)
        $this->actingAs($superAdmin)
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), [
                'qty' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.qty', 1);
    }

    /**
     * Test 4.2: Super Admin reduces qty → serials preserved → save blocked until resolved
     */
    public function test_super_admin_qty_reduce_serials_preserved_save_blocked(): void
    {
        $context = $this->createCheckoutContext('SUPER SERIAL MISMATCH');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-SUPER-MISMATCH', 100000, true);

        $this->createSerialNumber($product, $context['location'], 'SN-MISMATCH-1');
        $this->createSerialNumber($product, $context['location'], 'SN-MISMATCH-2');
        $this->createSerialNumber($product, $context['location'], 'SN-MISMATCH-3');

        // Create Super Admin
        $superAdmin = $this->createSuperAdminUser($context['setting']);

        $this->addCartLine($superAdmin, $context['setting'], $product->id, 3);
        $snapshot = $this->cartSnapshot($superAdmin, $context['setting']);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign 3 serials
        $this->actingAs($superAdmin)
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-MISMATCH-1', 'SN-MISMATCH-2', 'SN-MISMATCH-3'],
            ])
            ->assertOk();

        // Reduce qty to 2 - serials preserved (mismatch will be caught at checkout)
        $this->actingAs($superAdmin)
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.lines.update', ['lineId' => $lineId]), [
                'qty' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.qty', 2)
            ->assertJsonPath('cart_snapshot.lines.0.assigned_serials', ['SN-MISMATCH-1', 'SN-MISMATCH-2', 'SN-MISMATCH-3']);
    }

    // --- Helper Methods ---

    private function createCheckoutContext(string $name): array
    {
        $setting = $this->createSetting($name);
        $cashier = $this->createUserForSetting($setting, $name . '-cashier', ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.cart.line.reduce']);
        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);
        $this->seedPaymentMethods($setting);

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
            'location' => $location,
            'terminal' => $terminal,
            'session' => $session,
            'cashier' => $cashier,
        ];
    }

    private function createSetting(string $name): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'test.' . $suffix . '@example.com',
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
        // Create permissions first
        $permissionObjects = [];
        foreach ($permissions as $permName) {
            $permissionObjects[] = Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => strtoupper($roleName) . '-' . $setting->id]);
        $role->syncPermissions($permissionObjects);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $index = $this->sequence++;

        $location = Location::create([
            'name' => 'POS TEST LOC ' . $index,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-TEST-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Test Terminal ' . $index,
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

    private function createStockedProduct(
        Setting $setting,
        Location $location,
        string $productCode,
        int $quantity,
        bool $requiresSerial,
    ): Product {
        $unit = Unit::firstOrCreate([
            'name' => 'PCS',
            'short_name' => 'PC',
        ]);
        $category = Category::firstOrCreate(
            ['category_code' => $productCode . '-CAT'],
            [
                'category_name' => $productCode . ' CATEGORY',
                'created_by' => 1,
                'setting_id' => $setting->id,
            ]
        );

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $productCode . ' NAME',
            'product_code' => $productCode,
            'barcode' => $productCode . '-BAR',
            'product_quantity' => $quantity,
            'product_cost' => 5000,
            'product_price' => 100000,
            'product_unit' => 'PC',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => $requiresSerial,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'setting_id' => $setting->id,
            'selling_price' => 100000,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'quantity_non_tax' => $quantity,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        return $product;
    }

    private function createSerialNumber(Product $product, Location $location, string $serialNumber): ProductSerialNumber
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => $serialNumber,
            'status' => 'ACTIVE',
        ]);
    }

    private function seedPaymentMethods(Setting $setting): array
    {
        $methods = [];

        foreach (['CASH' => true, 'TRANSFER' => false, 'QRIS' => false] as $name => $isCash) {
            $methodSuffix = $this->sequence++;
            $coaId = DB::table('chart_of_accounts')->insertGetId([
                'name' => "COA $name " . $methodSuffix,
                'account_number' => "ACC-$name-" . $methodSuffix,
                'category' => 'Kas & Bank',
                'setting_id' => $setting->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $methods[strtolower($name)] = PaymentMethod::create([
                'name' => "$name POS $methodSuffix",
                'coa_id' => $coaId,
                'is_cash' => $isCash,
                'requires_reference' => !$isCash,
            ]);

            DB::table('setting_pos_payment_methods')->updateOrInsert(
                [
                    'setting_id' => $setting->id,
                    'payment_method_id' => $methods[strtolower($name)]->id,
                ],
                [
                    'is_enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        return $methods;
    }

    private function selectCustomerInCart(User $cashier, Setting $setting, ?string $customer = null): void
    {
        // Implementation if needed
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
            ->json('cart_snapshot');
    }

    private function createSuperAdminUser(Setting $setting): User
    {
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        // Create and give Super Admin the required permissions for POS
        $permissionNames = ['pos.access', 'pos.sell', 'pos.sessions.open'];
        $permissions = [];
        foreach ($permissionNames as $permName) {
            $permissions[] = Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'web']);
        }
        $superAdminRole->syncPermissions($permissions);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($superAdminRole);
        $user->settings()->attach($setting->id, ['role_id' => $superAdminRole->id]);

        return $user;
    }
}
