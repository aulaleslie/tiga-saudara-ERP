<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
class POSShellSessionGuardTest extends TestCase
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
            'sales.access',
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_sell_route_redirects_to_session_open_when_no_active_session_exists(): void
    {
        $setting = $this->createSetting('BIZ SHELL A', true);
        $cashier = $this->createUserForSetting(
            $setting,
            'POS SHELL CASHIER OPEN',
            ['pos.access', 'pos.sell', 'pos.sessions.open']
        );

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'));

        $response->assertRedirect(route('pos.sessions.create'));
        $response->assertSessionHas('warning', 'Active POS session is required before accessing POS sell screen.');
    }

    public function test_sell_route_redirects_but_session_open_remains_forbidden_without_permission(): void
    {
        $setting = $this->createSetting('BIZ SHELL B', true);
        $cashier = $this->createUserForSetting(
            $setting,
            'POS SHELL CASHIER NO OPEN',
            ['pos.access', 'pos.sell']
        );

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertRedirect(route('pos.sessions.create'));

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.create'))
            ->assertForbidden();
    }

    public function test_sell_shell_renders_session_context_and_layout_primitives_with_active_session(): void
    {
        $setting = $this->createSetting('BIZ SHELL C', true);
        $cashier = $this->createUserForSetting(
            $setting,
            'POS SHELL CASHIER ACTIVE',
            ['pos.access', 'pos.sell', 'pos.sessions.open']
        );

        $activeSession = $this->createActiveSessionForCashier($setting, $cashier);
        $cashEventCountBefore = DB::table('pos_session_cash_events')->count();
        $checkoutCountBefore = DB::table('pos_checkouts')->count();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertOk()
            ->assertSee('Layar Kasir POS')
            ->assertSee('Sesi #' . $activeSession->id)
            ->assertSee('Kasir Information')
            ->assertSee('Navigation')
            ->assertSee('Search')
            ->assertSee('Keranjang')
            ->assertSee('Pelanggan')
            ->assertSee('Pembayaran')
            ->assertSee('Pilih Pembayaran')
            ->assertDontSee('Override')
            ->assertSee('pos-shell-posting-note');

        $this->assertSame($cashEventCountBefore, DB::table('pos_session_cash_events')->count());
        $this->assertSame($checkoutCountBefore, DB::table('pos_checkouts')->count());
    }

    public function test_sell_route_remains_blocked_by_feature_flag_when_disabled(): void
    {
        $setting = $this->createSetting('BIZ SHELL D', false);
        $cashier = $this->createUserForSetting(
            $setting,
            'POS SHELL CASHIER DISABLED',
            ['sales.access', 'pos.access', 'pos.sell', 'pos.sessions.open']
        );

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertRedirect(route('sales.index'))
            ->assertSessionHas('warning', 'POS is disabled for the current business.');
    }

    public function test_sell_route_redirects_to_session_open_when_only_closed_session_exists(): void
    {
        $setting = $this->createSetting('BIZ SHELL E', true);
        $cashier = $this->createUserForSetting(
            $setting,
            'POS SHELL CASHIER CLOSED',
            ['pos.access', 'pos.sell', 'pos.sessions.open']
        );

        $this->createClosedSessionForCashier($setting, $cashier);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertRedirect(route('pos.sessions.create'))
            ->assertSessionHas('warning', 'Active POS session is required before accessing POS sell screen.');
    }

    private function createSetting(string $name, bool $posEnabled): Setting
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
            'pos_enabled' => $posEnabled,
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

    private function createActiveSessionForCashier(Setting $setting, User $cashier): PosSession
    {
        $terminal = $this->createTerminalForSetting($setting);

        return PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 0,
            'expected_cash_total' => 0,
            'active_marker' => 1,
        ]);
    }

    private function createClosedSessionForCashier(Setting $setting, User $cashier): PosSession
    {
        $terminal = $this->createTerminalForSetting($setting);

        return PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_CLOSED,
            'opened_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
            'opened_by' => $cashier->id,
            'closed_by' => $cashier->id,
            'opening_float_total' => 50000,
            'expected_cash_total' => 50000,
            'counted_cash_total' => 50000,
            'variance_total' => 0,
            'active_marker' => null,
        ]);
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'SHELL-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'Shell Terminal ' . $sequence,
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
}
