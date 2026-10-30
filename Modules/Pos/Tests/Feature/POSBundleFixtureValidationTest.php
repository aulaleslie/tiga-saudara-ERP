<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosRolePolicyService;
use Modules\Pos\Support\PosPermissionMatrix;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSBundleFixtureValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [];
        foreach (PosPermissionMatrix::capabilityClusters() as $cluster) {
            $permissions = array_merge($permissions, $cluster['permissions']);
        }
        foreach (PosPermissionMatrix::supportedBundles() as $bundle) {
            $permissions = array_merge($permissions, $bundle['permissions']);
        }

        foreach (array_unique($permissions) as $permission) {
            if ($permission !== '') {
                Permission::findOrCreate($permission, 'web');
            }
        }

        Role::findOrCreate('Super Admin', 'web');
    }

    public function test_representative_owner_manager_cashier_and_floor_staff_fixtures_match_bundle_expectations(): void
    {
        $setting = $this->createSetting();
        $owner = $this->createUserForSetting($setting, 'Super Admin', []);
        $manager = $this->createUserForSetting($setting, 'POS Manager Fixture', PosPermissionMatrix::supportedBundles()['manager']['permissions']);
        $cashier = $this->createUserForSetting($setting, 'POS Cashier Fixture', PosPermissionMatrix::supportedBundles()['cashier']['permissions']);
        $floorStaff = $this->createUserForSetting($setting, 'POS Floor Fixture', PosPermissionMatrix::supportedBundles()['floor_staff']['permissions']);

        $this->createActiveSession($setting, $this->createTerminal($setting, 'OWNER'), $owner);
        $this->createActiveSession($setting, $this->createTerminal($setting, 'MANAGER'), $manager);
        $this->createActiveSession($setting, $this->createTerminal($setting, 'CASHIER'), $cashier);
        $this->createActiveSession($setting, $this->createTerminal($setting, 'FLOOR'), $floorStaff);

        /** @var PosRolePolicyService $rolePolicy */
        $rolePolicy = app(PosRolePolicyService::class);

        $managerFlags = $rolePolicy->capabilityFlags($manager);
        $cashierFlags = $rolePolicy->capabilityFlags($cashier);
        $floorFlags = $rolePolicy->capabilityFlags($floorStaff);
        $cashierNoTerminalFlags = $rolePolicy->capabilityFlags($cashier, new PosSession(['terminal_id' => null]));
        $managerNoTerminalFlags = $rolePolicy->capabilityFlags($manager, new PosSession(['terminal_id' => null]));

        $this->assertSame(PosRolePolicyService::PROFILE_MANAGER, $managerFlags['role']);
        $this->assertTrue($managerFlags['can_checkout']);
        $this->assertTrue($managerFlags['can_view_sessions']);
        $this->assertTrue($managerFlags['can_admin_close_sessions']);

        $this->assertSame(PosRolePolicyService::PROFILE_CASHIER, $cashierFlags['role']);
        $this->assertTrue($cashierFlags['can_checkout']);
        $this->assertFalse($cashierFlags['can_view_sessions']);
        $this->assertTrue($cashierFlags['can_load_draft']);

        $this->assertSame(PosRolePolicyService::PROFILE_HELPER, $floorFlags['role']);
        $this->assertFalse($floorFlags['can_checkout']);
        $this->assertTrue($floorFlags['can_save_draft']);
        $this->assertTrue($floorFlags['can_load_draft']);

        $this->assertTrue($managerNoTerminalFlags['can_checkout']);
        $this->assertTrue($managerNoTerminalFlags['can_use_payment_flow']);
        $this->assertTrue($managerNoTerminalFlags['can_search_payment_methods']);

        $this->assertTrue($cashierNoTerminalFlags['can_checkout']);
        $this->assertFalse($cashierNoTerminalFlags['can_use_payment_flow']);
        $this->assertFalse($cashierNoTerminalFlags['can_search_payment_methods']);
        $this->assertFalse($cashierNoTerminalFlags['has_assigned_terminal']);

        $this->actingAs($owner)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertOk();
    }

    public function test_pos_checkout_debt_request_is_registered_in_exceptions_cluster_without_default_bundle_grants(): void
    {
        $allPermissions = require base_path('app/Config/Permissions.php');
        $this->assertArrayHasKey('POS', $allPermissions);
        $this->assertArrayHasKey('pos.checkout.debt.request', $allPermissions['POS']);

        $clusters = PosPermissionMatrix::capabilityClusters();
        $this->assertArrayHasKey('exceptions', $clusters);
        $this->assertContains('pos.checkout.debt.request', $clusters['exceptions']['permissions']);

        foreach (PosPermissionMatrix::supportedBundles() as $bundleKey => $bundle) {
            $this->assertNotContains(
                'pos.checkout.debt.request',
                $bundle['permissions'],
                "Default bundle [{$bundleKey}] must not automatically grant pos.checkout.debt.request"
            );
        }
    }

    public function test_pos_sessions_open_total_only_is_registered_in_exceptions_cluster_without_default_bundle_grants(): void
    {
        $allPermissions = require base_path('app/Config/Permissions.php');
        $this->assertArrayHasKey('POS', $allPermissions);
        $this->assertArrayHasKey('pos.sessions.open.total-only', $allPermissions['POS']);

        $clusters = PosPermissionMatrix::capabilityClusters();
        $this->assertArrayHasKey('exceptions', $clusters);
        $this->assertContains('pos.sessions.open.total-only', $clusters['exceptions']['permissions']);

        foreach (PosPermissionMatrix::supportedBundles() as $bundleKey => $bundle) {
            $this->assertNotContains(
                'pos.sessions.open.total-only',
                $bundle['permissions'],
                "Default bundle [{$bundleKey}] must not automatically grant pos.sessions.open.total-only"
            );
        }
    }

    public function test_independent_flow_capabilities_for_payment_only_direct_debt_request_debt_combined_neither_and_super_admin(): void
    {
        $setting = $this->createSetting();
        $terminal = $this->createTerminal($setting, 'FLOWS');
        $sessionWithTerminal = new PosSession(['terminal_id' => $terminal->id]);
        $sessionWithoutTerminal = new PosSession(['terminal_id' => null]);

        $paymentOnly = $this->createUserForSetting($setting, 'Payment Only Cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.transactions.save', 'pos.transactions.load', 'pos.checkout.payment',
        ]);
        $directDebtOnly = $this->createUserForSetting($setting, 'Direct Debt Only Cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.transactions.save', 'pos.transactions.load', 'pos.checkout.debt',
        ]);
        $requestDebtOnly = $this->createUserForSetting($setting, 'Request Debt Only Cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.transactions.save', 'pos.transactions.load', 'pos.checkout.debt.request',
        ]);
        $combinedPaymentAndRequestDebt = $this->createUserForSetting($setting, 'Payment And Request Debt Cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.transactions.save', 'pos.transactions.load', 'pos.checkout.payment', 'pos.checkout.debt.request',
        ]);
        $combinedDirectAndRequestDebt = $this->createUserForSetting($setting, 'Direct And Request Debt Cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.transactions.save', 'pos.transactions.load', 'pos.checkout.debt', 'pos.checkout.debt.request',
        ]);
        $neitherCheckout = $this->createUserForSetting($setting, 'No Checkout Helper', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.transactions.save', 'pos.transactions.load',
        ]);
        $superAdmin = $this->createUserForSetting($setting, 'Super Admin', []);

        /** @var PosRolePolicyService $rolePolicy */
        $rolePolicy = app(PosRolePolicyService::class);

        // 1. Payment-only
        $paymentFlags = $rolePolicy->capabilityFlags($paymentOnly, $sessionWithTerminal);
        $this->assertTrue($paymentFlags['can_checkout']);
        $this->assertTrue($paymentFlags['has_payment_checkout_authority']);
        $this->assertFalse($paymentFlags['has_debt_checkout_authority']);
        $this->assertFalse($paymentFlags['has_direct_debt_checkout_authority']);
        $this->assertFalse($paymentFlags['has_request_debt_checkout_authority']);
        $this->assertFalse($paymentFlags['requires_debt_approval']);
        $this->assertFalse($paymentFlags['can_switch_checkout_flow']);
        $this->assertTrue($paymentFlags['can_use_payment_flow']);
        $this->assertTrue($paymentFlags['can_checkout_lunas']);
        $this->assertFalse($paymentFlags['can_checkout_kasbon']);

        $paymentNoTerminalFlags = $rolePolicy->capabilityFlags($paymentOnly, $sessionWithoutTerminal);
        $this->assertTrue($paymentNoTerminalFlags['can_checkout']);
        $this->assertFalse($paymentNoTerminalFlags['can_use_payment_flow']);
        $this->assertFalse($paymentNoTerminalFlags['can_checkout_lunas']);
        $this->assertFalse($paymentNoTerminalFlags['can_checkout_kasbon']);

        // 2. Direct-debt-only
        $directDebtFlags = $rolePolicy->capabilityFlags($directDebtOnly, $sessionWithTerminal);
        $this->assertTrue($directDebtFlags['can_checkout']);
        $this->assertFalse($directDebtFlags['has_payment_checkout_authority']);
        $this->assertTrue($directDebtFlags['has_debt_checkout_authority']);
        $this->assertTrue($directDebtFlags['has_direct_debt_checkout_authority']);
        $this->assertFalse($directDebtFlags['has_request_debt_checkout_authority']);
        $this->assertFalse($directDebtFlags['requires_debt_approval']);
        $this->assertFalse($directDebtFlags['can_switch_checkout_flow']);
        $this->assertTrue($directDebtFlags['can_use_payment_flow']);
        $this->assertFalse($directDebtFlags['can_checkout_lunas']);
        $this->assertTrue($directDebtFlags['can_checkout_kasbon']);
        $this->assertSame(PosRolePolicyService::PROFILE_CASHIER, $directDebtFlags['role']);

        $directDebtNoTerminalFlags = $rolePolicy->capabilityFlags($directDebtOnly, $sessionWithoutTerminal);
        $this->assertFalse($directDebtNoTerminalFlags['can_use_payment_flow']);
        $this->assertFalse($directDebtNoTerminalFlags['can_checkout_kasbon']);

        // 3. Request-debt-only
        $requestDebtFlags = $rolePolicy->capabilityFlags($requestDebtOnly, $sessionWithTerminal);
        $this->assertTrue($requestDebtFlags['can_checkout']);
        $this->assertFalse($requestDebtFlags['has_payment_checkout_authority']);
        $this->assertTrue($requestDebtFlags['has_debt_checkout_authority']);
        $this->assertFalse($requestDebtFlags['has_direct_debt_checkout_authority']);
        $this->assertTrue($requestDebtFlags['has_request_debt_checkout_authority']);
        $this->assertTrue($requestDebtFlags['requires_debt_approval']);
        $this->assertFalse($requestDebtFlags['can_switch_checkout_flow']);
        $this->assertTrue($requestDebtFlags['can_use_payment_flow']);
        $this->assertFalse($requestDebtFlags['can_checkout_lunas']);
        $this->assertTrue($requestDebtFlags['can_checkout_kasbon']);
        $this->assertSame(PosRolePolicyService::PROFILE_CASHIER, $requestDebtFlags['role']);

        // 4. Combined payment + request debt
        $combinedFlags = $rolePolicy->capabilityFlags($combinedPaymentAndRequestDebt, $sessionWithTerminal);
        $this->assertTrue($combinedFlags['can_checkout']);
        $this->assertTrue($combinedFlags['has_payment_checkout_authority']);
        $this->assertTrue($combinedFlags['has_debt_checkout_authority']);
        $this->assertTrue($combinedFlags['requires_debt_approval']);
        $this->assertTrue($combinedFlags['can_switch_checkout_flow']);
        $this->assertTrue($combinedFlags['can_checkout_lunas']);
        $this->assertTrue($combinedFlags['can_checkout_kasbon']);

        $combinedNoTerminalFlags = $rolePolicy->capabilityFlags($combinedPaymentAndRequestDebt, $sessionWithoutTerminal);
        $this->assertTrue($combinedNoTerminalFlags['can_switch_checkout_flow']);
        $this->assertFalse($combinedNoTerminalFlags['can_checkout_lunas']);
        $this->assertFalse($combinedNoTerminalFlags['can_checkout_kasbon']);

        // 5. Combined direct + request debt (direct takes precedence)
        $bothDebtFlags = $rolePolicy->capabilityFlags($combinedDirectAndRequestDebt, $sessionWithTerminal);
        $this->assertTrue($bothDebtFlags['has_debt_checkout_authority']);
        $this->assertTrue($bothDebtFlags['has_direct_debt_checkout_authority']);
        $this->assertTrue($bothDebtFlags['has_request_debt_checkout_authority']);
        $this->assertFalse($bothDebtFlags['requires_debt_approval']);

        // 6. Neither checkout permission
        $neitherFlags = $rolePolicy->capabilityFlags($neitherCheckout, $sessionWithTerminal);
        $this->assertFalse($neitherFlags['can_checkout']);
        $this->assertFalse($neitherFlags['has_payment_checkout_authority']);
        $this->assertFalse($neitherFlags['has_debt_checkout_authority']);
        $this->assertFalse($neitherFlags['can_use_payment_flow']);
        $this->assertFalse($neitherFlags['can_checkout_lunas']);
        $this->assertFalse($neitherFlags['can_checkout_kasbon']);
        $this->assertFalse($neitherFlags['can_switch_checkout_flow']);

        // 7. Super Admin (retains full authority and manager exception without terminal)
        $superAdminFlags = $rolePolicy->capabilityFlags($superAdmin, $sessionWithoutTerminal);
        $this->assertTrue($superAdminFlags['can_checkout']);
        $this->assertTrue($superAdminFlags['has_payment_checkout_authority']);
        $this->assertTrue($superAdminFlags['has_debt_checkout_authority']);
        $this->assertTrue($superAdminFlags['has_direct_debt_checkout_authority']);
        $this->assertFalse($superAdminFlags['requires_debt_approval']);
        $this->assertTrue($superAdminFlags['can_use_payment_flow']);
        $this->assertTrue($superAdminFlags['can_checkout_lunas']);
        $this->assertTrue($superAdminFlags['can_checkout_kasbon']);
        $this->assertTrue($superAdminFlags['can_switch_checkout_flow']);

        // 8. Request-debt-only user with oversight permissions does NOT qualify for manager terminal-less exception
        $requestDebtWithOversight = $this->createUserForSetting($setting, 'Request Debt Oversight User', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.debt.request',
            'pos.sessions.view',
            'pos.sessions.close-admin',
            'pos.transactions.edit.any',
        ]);
        $requestDebtOversightNoTerminalFlags = $rolePolicy->capabilityFlags($requestDebtWithOversight, $sessionWithoutTerminal);
        $this->assertFalse($requestDebtOversightNoTerminalFlags['is_manager_checkout_role']);
        $this->assertFalse($requestDebtOversightNoTerminalFlags['can_use_payment_flow']);
        $this->assertFalse($requestDebtOversightNoTerminalFlags['can_checkout_kasbon']);
    }

    public function test_pos_sell_shell_renders_flow_buttons_and_labels_per_permission_profile(): void
    {
        $setting = $this->createSetting();

        $paymentOnly = $this->createUserForSetting($setting, 'Shell Payment Only', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment',
        ]);
        $requestDebtOnly = $this->createUserForSetting($setting, 'Shell Request Debt Only', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.debt.request',
        ]);
        $directDebtOnly = $this->createUserForSetting($setting, 'Shell Direct Debt Only', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.debt',
        ]);
        $combinedRequestDebt = $this->createUserForSetting($setting, 'Shell Combined Request Debt', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.checkout.debt.request',
        ]);

        $this->createActiveSession($setting, $this->createTerminal($setting, 'SHELL-PAY'), $paymentOnly);
        $this->createActiveSession($setting, $this->createTerminal($setting, 'SHELL-REQ'), $requestDebtOnly);
        $this->createActiveSession($setting, $this->createTerminal($setting, 'SHELL-DIR'), $directDebtOnly);
        $this->createActiveSession($setting, $this->createTerminal($setting, 'SHELL-COMB'), $combinedRequestDebt);

        // Payment-only sees Bayar Lunas button, not Kasbon button
        $this->actingAs($paymentOnly)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertOk()
            ->assertSee('id="pos-checkout-final"', false)
            ->assertDontSee('id="pos-checkout-kasbon"', false);

        // Request-debt-only sees Kasbon · Perlu Persetujuan button, not Bayar Lunas button
        $this->actingAs($requestDebtOnly)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertOk()
            ->assertDontSee('id="pos-checkout-final"', false)
            ->assertSee('id="pos-checkout-kasbon"', false)
            ->assertSee('Kasbon · Perlu Persetujuan');

        // Direct-debt-only sees Kasbon button without approval suffix, not Bayar Lunas button
        $this->actingAs($directDebtOnly)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertOk()
            ->assertDontSee('id="pos-checkout-final"', false)
            ->assertSee('id="pos-checkout-kasbon"', false)
            ->assertDontSee('Kasbon · Perlu Persetujuan');

        // Combined user sees both buttons and approval suffix when debt access is request-only
        $this->actingAs($combinedRequestDebt)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertOk()
            ->assertSee('id="pos-checkout-final"', false)
            ->assertSee('id="pos-checkout-kasbon"', false)
            ->assertSee('Kasbon · Perlu Persetujuan');
    }

    private function createSetting(): Setting
    {
        return Setting::create([
            'company_name' => 'POS Bundle Fixture Biz',
            'company_email' => 'pos.bundle.fixture@example.com',
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
            'pos_transactions_enabled' => true,
        ]);
    }

    private function createTerminal(Setting $setting, string $suffix): PosTerminal
    {
        Location::create([
            'name' => 'POS Bundle Fixture Location ' . $suffix,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-BUNDLE-' . $suffix,
            'name' => 'POS Bundle Fixture Terminal ' . $suffix,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => false,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
    }

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $user = User::factory()->create();

        if ($roleName === 'Super Admin') {
            $role = Role::findOrCreate('Super Admin', 'web');
        } else {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $role->syncPermissions($permissions);
        }

        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createActiveSession(Setting $setting, PosTerminal $terminal, User $cashier): void
    {
        PosSession::create([
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
    }
}
