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
