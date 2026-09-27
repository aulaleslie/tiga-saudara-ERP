<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSSessionFinalizeEndpointTest extends TestCase
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
            'pos.supervisor.approval',
            'pos.sessions.approve-variance',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        if (! Schema::hasColumn('pos_sessions', 'finalized_at')) {
            Schema::table('pos_sessions', function (Blueprint $table): void {
                $table->timestamp('finalized_at')->nullable();
            });
        }
    }

    public function test_finalize_endpoint_rejects_closed_non_terminal_session_with_contextual_422(): void
    {
        $setting = $this->createSetting('BIZ FINALIZE ENDPOINT A');
        $supervisor = $this->createUserForSetting($setting, ['pos.access', 'pos.supervisor.approval']);
        $session = $this->createClosedSession($setting, $supervisor);

        $response = $this->actingAs($supervisor)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.finalize', ['session' => $session->id]), [
                'actual_cash_received' => 100000,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'POS terminal policy is missing.')
            ->assertJsonPath('error_code', 'terminal_policy_missing');

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => PosSession::STATUS_CLOSED,
            'finalized_at' => null,
        ]);
    }

    public function test_finalize_endpoint_keeps_terminal_backed_finalize_success_flow(): void
    {
        $setting = $this->createSetting('BIZ FINALIZE ENDPOINT B');
        $supervisor = $this->createUserForSetting($setting, ['pos.access', 'pos.supervisor.approval']);
        $terminal = $this->createTerminal($setting, 5000);
        $session = $this->createClosedSession($setting, $supervisor, $terminal, 100000);

        $response = $this->actingAs($supervisor)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.finalize', ['session' => $session->id]), [
                'actual_cash_received' => 100000,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', PosSession::STATUS_FINALIZED)
            ->assertJsonPath('approval_result', 'WITHIN_THRESHOLD');

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => PosSession::STATUS_FINALIZED,
        ]);
    }

    public function test_finalize_endpoint_keeps_variance_approval_gate_for_terminal_sessions(): void
    {
        $setting = $this->createSetting('BIZ FINALIZE ENDPOINT C');
        $supervisor = $this->createUserForSetting($setting, ['pos.access', 'pos.supervisor.approval']);
        $terminal = $this->createTerminal($setting, 5000);
        $session = $this->createClosedSession($setting, $supervisor, $terminal, 100000);

        $response = $this->actingAs($supervisor)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.finalize', ['session' => $session->id]), [
                'actual_cash_received' => 120000,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('requires_variance_approval', true)
            ->assertJsonPath('status', PosSession::STATUS_CLOSED);

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => PosSession::STATUS_CLOSED,
            'finalized_at' => null,
        ]);
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

    private function createUserForSetting(Setting $setting, array $permissions): User
    {
        $role = Role::create(['name' => 'Role_' . uniqid()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminal(Setting $setting, float $threshold): PosTerminal
    {
        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'FIN-' . uniqid(),
            'name' => 'Finalize Terminal',
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => $threshold,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
    }

    private function createClosedSession(
        Setting $setting,
        User $cashier,
        ?PosTerminal $terminal = null,
        float $openingFloat = 100000
    ): PosSession {
        $session = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal?->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_CLOSED,
            'opened_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
            'opened_by' => $cashier->id,
            'closed_by' => $cashier->id,
            'opening_float_total' => $openingFloat,
            'expected_cash_total' => $openingFloat,
            'counted_cash_total' => $openingFloat,
            'variance_total' => 0,
        ]);

        PosSessionCashEvent::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'event_type' => PosSessionCashEvent::EVENT_OPEN_FLOAT,
            'direction' => PosSessionCashEvent::DIRECTION_IN,
            'amount' => $openingFloat,
            'performed_by' => $cashier->id,
            'occurred_at' => now()->subHours(2),
        ]);

        return $session;
    }
}
