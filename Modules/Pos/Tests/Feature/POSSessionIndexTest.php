<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
class POSSessionIndexTest extends TestCase
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
            'pos.access',
            'pos.sessions.view',
            'pos.sessions.open',
            'pos.sell',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_index_page_returns_200_with_view_permission(): void
    {
        $setting = $this->createSetting('BIZ INDEX A');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sessions.view']);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index'));

        $response->assertOk();
        $response->assertSee('Riwayat Sesi POS');
    }

    public function test_index_page_forbidden_without_view_permission(): void
    {
        $setting = $this->createSetting('BIZ INDEX B');
        $user = $this->createUserForSetting($setting, ['pos.access']);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index'))
            ->assertForbidden();
    }

    public function test_index_lists_both_open_and_closed_sessions(): void
    {
        $setting = $this->createSetting('BIZ INDEX C');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sessions.view']);
        $terminal = $this->createTerminal($setting);

        // Create an open session
        $openSession = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opened_by' => $user->id,
            'opening_float_total' => 100000,
            'active_marker' => 1,
        ]);

        // Create a closed session
        $closedSession = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $user->id,
            'status' => 'CLOSED',
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subDay()->addHours(8),
            'opened_by' => $user->id,
            'closed_by' => $user->id,
            'opening_float_total' => 100000,
            'counted_cash_total' => 150000,
            'variance_total' => 0,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index'));

        $response->assertSee('AKTIF');
        $response->assertSee('SELESAI');
        $response->assertSee($terminal->code);
    }

    public function test_index_supports_status_filtering(): void
    {
        $setting = $this->createSetting('BIZ INDEX D');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sessions.view']);
        $terminal = $this->createTerminal($setting);

        // Open session
        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opened_by' => $user->id,
            'active_marker' => 1,
        ]);

        // Closed session
        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $user->id,
            'status' => 'CLOSED',
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subDay()->addHours(8),
            'opened_by' => $user->id,
        ]);

        // Filter for OPEN
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index', ['status' => 'OPEN']));

        $response->assertSee('AKTIF');
        $response->assertDontSee('SELESAI');

        // Filter for CLOSED
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index', ['status' => 'CLOSED']));

        $response->assertSee('SELESAI');
        $response->assertDontSee('AKTIF');
    }

    public function test_index_supports_terminal_id_filtering(): void
    {
        $setting = $this->createSetting('BIZ INDEX E');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sessions.view']);

        $terminal1 = $this->createTerminalWithCode($setting, 'TRM-01');
        $terminal2 = $this->createTerminalWithCode($setting, 'TRM-02');

        // Session for terminal 1
        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal1->id,
            'cashier_user_id' => $user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opened_by' => $user->id,
            'active_marker' => 1,
        ]);

        // Session for terminal 2
        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal2->id,
            'cashier_user_id' => $user->id,
            'status' => 'CLOSED',
            'opened_at' => now()->subDay(),
            'opened_by' => $user->id,
        ]);

        // Filter for terminal 1
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index', ['terminal_id' => $terminal1->id]));

        $response->assertSee('TRM-01');
        $response->assertDontSee('TRM-02');
        $response->assertSee('Terminal: TRM-01');

        // Filter for terminal 2
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index', ['terminal_id' => $terminal2->id]));

        $response->assertSee('TRM-02');
        $response->assertDontSee('TRM-01');
        $response->assertSee('Terminal: TRM-02');
    }

    private function createTerminalWithCode(Setting $setting, string $code): PosTerminal
    {
        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => $code,
            'name' => 'Terminal ' . $code,
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

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => 'test@example.com',
            'company_phone' => '0800',
            'company_address' => 'Addr',
            'default_currency_id' => Currency::query()->first()->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'F',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
        ]);
    }

    private function createUserForSetting(Setting $setting, array $permissions): User
    {
        $role = Role::create(['name' => 'Role_' . uniqid()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminal(Setting $setting): PosTerminal
    {
        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'T1',
            'name' => 'Terminal 1',
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
