<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSPermissionRoleMappingTest extends TestCase
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

        foreach ([
            'sales.access',
            'pos.access',
            'pos.sell',
            'pos.overrides.price',
            'pos.terminals.access',
            'pos.terminals.edit',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Route::middleware(['web', 'auth', 'role.setting', 'can:pos.overrides.price'])
            ->get('/pos/testing/permission-probe/price-override', fn () => response('OK'));
    }

    public function test_sell_route_requires_pos_access_and_pos_sell_permissions(): void
    {
        $setting = $this->createSetting('BIZ A');

        $userWithBoth = $this->createUserForSetting($setting, 'Cashier Both', ['pos.access', 'pos.sell']);
        $this->createActiveSessionForCashier($setting, $userWithBoth);
        $this->actingAs($userWithBoth)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertOk();

        $userMissingSell = $this->createUserForSetting($setting, 'Cashier No Sell', ['pos.access']);
        $this->actingAs($userMissingSell)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertForbidden();

        $userMissingAccess = $this->createUserForSetting($setting, 'Cashier No Access', ['pos.sell']);
        $this->actingAs($userMissingAccess)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertForbidden();
    }

    public function test_override_action_is_blocked_without_pos_overrides_price_permission_and_allowed_with_it(): void
    {
        $setting = $this->createSetting('BIZ A');

        $cashier = $this->createUserForSetting($setting, 'Cashier', ['pos.access', 'pos.sell']);
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get('/pos/testing/permission-probe/price-override')
            ->assertForbidden();

        $supervisor = $this->createUserForSetting($setting, 'Supervisor', ['pos.access', 'pos.sell', 'pos.overrides.price']);
        $this->actingAs($supervisor)
            ->withSession(['setting_id' => $setting->id])
            ->get('/pos/testing/permission-probe/price-override')
            ->assertOk();
    }

    public function test_terminal_read_requires_pos_terminals_access_and_write_requires_pos_terminals_edit(): void
    {
        $setting = $this->createSetting('BIZ A');
        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'COUNTER-01',
            'name' => 'Terminal A',
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
        ]);

        $readOnly = $this->createUserForSetting($setting, 'Read Only', ['pos.terminals.access']);
        $this->actingAs($readOnly)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.terminals.index'))
            ->assertOk()
            ->assertSee('Terminal POS');

        $noTerminalAccess = $this->createUserForSetting($setting, 'No Terminal Access', []);
        $this->actingAs($noTerminalAccess)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.terminals.index'))
            ->assertForbidden();

        $this->actingAs($readOnly)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.terminals.create'))
            ->assertForbidden();

        $editor = $this->createUserForSetting($setting, 'Editor', ['pos.terminals.access', 'pos.terminals.edit']);
        $this->actingAs($editor)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.terminals.create'))
            ->assertOk()
            ->assertSee('Buat Terminal POS');

        $this->actingAs($editor)
            ->withSession(['setting_id' => $setting->id])
            ->delete(route('pos.terminals.destroy', $terminal->id))
            ->assertRedirect(route('pos.terminals.index'));

        $this->assertDatabaseHas('pos_terminals', [
            'id' => $terminal->id,
            'is_active' => 0,
        ]);
    }

    public function test_terminal_edit_still_returns_not_found_for_cross_setting_even_with_permissions(): void
    {
        $settingA = $this->createSetting('BIZ A');
        $settingB = $this->createSetting('BIZ B');

        $locationB = Location::create([
            'name' => 'LOC B',
            'setting_id' => $settingB->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $settingB->id,
            'code' => 'COUNTER-B',
            'name' => 'Terminal B',
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
        ]);

        $adminConfig = $this->createUserForSetting($settingA, 'Admin Config', ['pos.terminals.access', 'pos.terminals.edit']);

        $this->actingAs($adminConfig)
            ->withSession(['setting_id' => $settingA->id])
            ->get(route('pos.terminals.edit', $terminal->id))
            ->assertNotFound();
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

    private function createActiveSessionForCashier(Setting $setting, User $user): void
    {
        $location = Location::create([
            'name' => 'SESSION LOC ' . $setting->id,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'SESSION-' . $setting->id . '-' . $user->id,
            'name' => 'Session Terminal',
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
        ]);

        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $user->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $user->id,
            'opening_float_total' => 0,
            'expected_cash_total' => 0,
            'active_marker' => 1,
        ]);
    }
}
