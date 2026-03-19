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
            'pos.sessions.close-admin',
            'pos.supervisor.approval',
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
        $openTerminal = $this->createTerminalWithCode($setting, 'OPEN-TRM');
        $closedTerminal = $this->createTerminalWithCode($setting, 'CLOSED-TRM');

        // Open session
        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $openTerminal->id,
            'cashier_user_id' => $user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opened_by' => $user->id,
            'active_marker' => 1,
        ]);

        // Closed session
        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $closedTerminal->id,
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
        $response->assertSee('OPEN-TRM');
        $response->assertDontSee('CLOSED-TRM');

        // Filter for CLOSED
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index', ['status' => 'CLOSED']));

        $response->assertSee('CLOSED-TRM');
        $response->assertDontSee('OPEN-TRM');
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

    public function test_index_renders_for_open_non_terminal_session_with_admin_permission(): void
    {
        $setting = $this->createSetting('BIZ INDEX F');
        $user = $this->createUserForSetting($setting, [
            'pos.access',
            'pos.sessions.view',
            'pos.sessions.close-admin',
        ]);

        $session = $this->createNonTerminalSession($setting, $user, 'OPEN');

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index'));

        $response->assertOk();
        $response->assertSee('Non-Terminal');
        $response->assertSee('data-session-id="' . $session->id . '"', false);
        $response->assertSee('data-session-code="Non-Terminal"', false);
    }

    public function test_index_shows_admin_close_action_for_open_non_terminal_session(): void
    {
        $setting = $this->createSetting('BIZ INDEX G');
        $user = $this->createUserForSetting($setting, [
            'pos.access',
            'pos.sessions.view',
            'pos.sessions.close-admin',
        ]);

        $session = $this->createNonTerminalSession($setting, $user, 'OPEN');

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index'));

        $response->assertOk();
        $response->assertSee('data-bs-target="#closeAdminModal" data-session-id="' . $session->id . '"', false);
    }

    public function test_index_keeps_identical_table_structure_for_terminal_and_non_terminal_rows(): void
    {
        $setting = $this->createSetting('BIZ INDEX H');
        $user = $this->createUserForSetting($setting, [
            'pos.access',
            'pos.sessions.view',
            'pos.sessions.close-admin',
        ]);
        $terminal = $this->createTerminal($setting);

        $terminalSession = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opened_by' => $user->id,
            'opening_float_total' => 100000,
            'active_marker' => 1,
        ]);
        $nonTerminalSession = $this->createNonTerminalSession($setting, $user, 'OPEN');

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index'));

        $response->assertOk();

        $html = $response->getContent();
        $terminalRow = $this->extractSessionTableRow($html, $terminalSession->id);
        $nonTerminalRow = $this->extractSessionTableRow($html, $nonTerminalSession->id);

        $this->assertSame(12, substr_count($terminalRow, '<td'));
        $this->assertSame(12, substr_count($nonTerminalRow, '<td'));
    }

    public function test_index_hides_finalize_for_closed_non_terminal_and_shows_for_terminal_session(): void
    {
        $setting = $this->createSetting('BIZ INDEX I');
        $user = $this->createUserForSetting($setting, [
            'pos.access',
            'pos.sessions.view',
            'pos.supervisor.approval',
        ]);
        $terminal = $this->createTerminal($setting);

        $terminalSession = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $user->id,
            'status' => 'CLOSED',
            'opened_at' => now()->subHours(4),
            'closed_at' => now()->subHour(),
            'opened_by' => $user->id,
            'closed_by' => $user->id,
            'opening_float_total' => 100000,
            'counted_cash_total' => 100000,
            'variance_total' => 0,
        ]);

        $nonTerminalSession = $this->createNonTerminalSession($setting, $user, 'CLOSED');

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index'));

        $response->assertOk();
        $response->assertSee('data-bs-target="#finalizeModal" data-session-id="' . $terminalSession->id . '"', false);
        $response->assertDontSee('data-bs-target="#finalizeModal" data-session-id="' . $nonTerminalSession->id . '"', false);
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

    private function createNonTerminalSession(Setting $setting, User $user, string $status): PosSession
    {
        return PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => null,
            'cashier_user_id' => $user->id,
            'status' => $status,
            'opened_at' => now()->subHours(3),
            'closed_at' => $status === 'CLOSED' ? now()->subHours(1) : null,
            'opened_by' => $user->id,
            'closed_by' => $status === 'CLOSED' ? $user->id : null,
            'opening_float_total' => 100000,
            'counted_cash_total' => $status === 'CLOSED' ? 100000 : null,
            'variance_total' => 0,
            'active_marker' => null,
        ]);
    }

    private function extractSessionTableRow(string $html, int $sessionId): string
    {
        preg_match_all('/<tr>[\s\S]*?<\/tr>/', $html, $matches);

        $needle = 'data-session-id="' . $sessionId . '"';
        foreach ($matches[0] ?? [] as $row) {
            if (str_contains($row, $needle)) {
                return $row;
            }
        }

        $this->fail('Failed to locate table row for session id ' . $sessionId);
    }
}
