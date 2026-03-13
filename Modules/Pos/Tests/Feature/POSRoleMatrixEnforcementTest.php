<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSRoleMatrixEnforcementTest extends TestCase
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
            'pos.cart.clear',
            'pos.cart.line.remove',
            'pos.cart.line.reduce',
            'pos.overrides.price',
            'pos.supervisor.approval',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_floor_and_cashier_require_approval_for_clear_cart_while_manager_can_clear_directly(): void
    {
        $setting = $this->createSetting('ROLE MATRIX CLEAR');
        $floor = $this->createUserForSetting($setting, 'Floor Staff', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $cashier = $this->createUserForSetting($setting, 'Cashier Staff', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $manager = $this->createUserForSetting($setting, 'Store Manager', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.cart.clear',
            'pos.overrides.price',
            'pos.supervisor.approval',
        ]);

        [$terminalFloor, $location] = $this->createTerminalForSetting($setting);
        [$terminalCashier] = $this->createTerminalForSetting($setting);
        [$terminalManager] = $this->createTerminalForSetting($setting);
        $product = $this->createStockedProduct($setting, $location, 'ROLE-MTRX-CLEAR-001');

        $this->createSession($setting, $terminalFloor, $floor);
        $this->createSession($setting, $terminalCashier, $cashier);
        $this->createSession($setting, $terminalManager, $manager);

        $this->actingAs($floor)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();
        $this->actingAs($floor)->withSession(['setting_id' => $setting->id])
            ->deleteJson(route('pos.sell.cart.clear'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'APPROVAL_REQUIRED');

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->deleteJson(route('pos.sell.cart.clear'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'APPROVAL_REQUIRED');

        $this->actingAs($manager)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();
        $this->actingAs($manager)->withSession(['setting_id' => $setting->id])
            ->deleteJson(route('pos.sell.cart.clear'))
            ->assertOk();
    }

    public function test_checkout_is_blocked_for_floor_staff_and_allowed_for_cashier_and_manager_roles(): void
    {
        $setting = $this->createSetting('ROLE MATRIX CHECKOUT');
        $floor = $this->createUserForSetting($setting, 'Floor Staff', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $cashier = $this->createUserForSetting($setting, 'Cashier Staff', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $manager = $this->createUserForSetting($setting, 'Store Manager', ['pos.access', 'pos.sell', 'pos.sessions.open']);

        [$terminalFloor] = $this->createTerminalForSetting($setting);
        [$terminalCashier] = $this->createTerminalForSetting($setting);
        [$terminalManager] = $this->createTerminalForSetting($setting);

        $this->createSession($setting, $terminalFloor, $floor);
        $this->createSession($setting, $terminalCashier, $cashier);
        $this->createSession($setting, $terminalManager, $manager);

        $paymentMethodId = $this->createPaymentMethodForSetting($setting);

        $payload = [
            'idempotency_key' => 'role-matrix-' . uniqid(),
            'payment' => [
                'payment_method_id' => $paymentMethodId,
                'amount_paid' => 10000,
            ],
        ];

        $this->actingAs($floor)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload)
            ->assertStatus(403)
            ->assertJsonPath('code', 'CHECKOUT_NOT_ALLOWED');

        $cashierResponse = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
        $this->assertNotSame(403, $cashierResponse->status());

        $managerResponse = $this->actingAs($manager)->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
        $this->assertNotSame(403, $managerResponse->status());
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
     * @return array{0: PosTerminal, 1: Location}
     */
    private function createTerminalForSetting(Setting $setting): array
    {
        $sequence = $this->terminalSequence++;
        $location = Location::create([
            'name' => 'ROLE MATRIX LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'ROLE-MTRX-' . $sequence,
            'name' => 'Role Matrix Terminal ' . $sequence,
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

        return [$terminal, $location];
    }

    private function createSession(Setting $setting, PosTerminal $terminal, User $user): void
    {
        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $user->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $user->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);
    }

    private function createStockedProduct(Setting $setting, Location $location, string $code): Product
    {
        $category = Category::firstOrCreate(
            ['category_code' => 'ROLE-MTRX-CAT-' . $setting->id],
            [
                'category_name' => 'Role Matrix Category',
                'setting_id' => $setting->id,
                'created_by' => User::query()->value('id') ?? User::factory()->create()->id,
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
            'product_name' => 'Role Matrix Product ' . $code,
            'product_code' => $code,
            'barcode' => $code . '-BC',
            'product_quantity' => 30,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 30,
            'quantity_non_tax' => 30,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        ProductPrice::updateOrCreate(
            [
                'product_id' => $product->id,
                'setting_id' => $setting->id,
            ],
            [
                'sale_price' => 10000,
                'tier_1_price' => null,
                'tier_2_price' => null,
                'last_purchase_price' => 5000,
                'average_purchase_price' => 5000,
                'purchase_tax_id' => null,
                'sale_tax_id' => null,
            ]
        );

        return $product;
    }

    private function createPaymentMethodForSetting(Setting $setting): int
    {
        $coa = ChartOfAccount::create([
            'name' => 'Role Matrix Cash',
            'account_number' => '1101-' . $setting->id . '-' . $this->terminalSequence,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $method = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coa->id,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        SettingPosPaymentMethod::updateOrCreate(
            [
                'setting_id' => $setting->id,
                'payment_method_id' => $method->id,
            ],
            [
                'is_enabled' => true,
            ]
        );

        return (int) $method->id;
    }
}
