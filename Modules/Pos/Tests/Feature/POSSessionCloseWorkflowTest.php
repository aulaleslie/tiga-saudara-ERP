<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSSessionCloseWorkflowTest extends TestCase
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
            'pos.sessions.close',
            'pos.supervisor.approval',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_close_no_variance_closes_without_supervisor_and_logs_close_count_event(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(100000, 5000);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), [
                'counted_cash_total' => 100000,
                'counted_denominations' => [
                    '100000' => 1,
                ],
                'notes' => 'Close balanced shift',
            ]);

        $response->assertOk()
            ->assertJson([
                'session_id' => $session->id,
                'status' => 'CLOSED',
                'counted_cash_total' => 100000.0,
                'expected_cash_total' => 100000.0,
                'variance_total' => 0.0,
                'variance_threshold' => 5000.0,
                'approval_result' => 'BYPASSED',
                'approval_id' => null,
            ])
            ->assertJsonStructure([
                'session_id',
                'status',
                'counted_cash_total',
                'expected_cash_total',
                'variance_total',
                'variance_threshold',
                'approval_id',
                'approval_result',
                'closed_at',
            ]);

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => 'CLOSED',
            'closed_by' => $cashier->id,
            'counted_cash_total' => 100000,
            'variance_total' => 0,
            'close_approved_by' => null,
            'active_marker' => null,
        ]);

        $this->assertDatabaseHas('pos_session_cash_events', [
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'event_type' => 'CLOSE_COUNT',
            'direction' => 'NEUTRAL',
            'amount' => 100000,
            'performed_by' => $cashier->id,
            'approved_by' => null,
            'notes' => 'CLOSE BALANCED SHIFT',
        ]);
    }

    public function test_close_variance_above_threshold_without_supervisor_credentials_is_blocked_and_stays_blind(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(100000, 1000);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), [
                'counted_cash_total' => 120000,
                'notes' => 'Need supervisor approval',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Supervisor approval is required to close session variance.',
                'requires_supervisor_approval' => true,
                'status' => 'CLOSING',
            ])
            ->assertJsonMissingPath('expected_cash_total')
            ->assertJsonMissingPath('variance_total');

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => 'CLOSING',
            'closed_at' => null,
            'active_marker' => 1,
        ]);

        $this->assertDatabaseMissing('pos_session_cash_events', [
            'pos_session_id' => $session->id,
            'event_type' => 'CLOSE_COUNT',
        ]);

        $this->assertDatabaseCount('pos_supervisor_approvals', 0);
    }

    public function test_close_variance_above_threshold_with_invalid_supervisor_pin_is_rejected_without_close_mutation(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(100000, 1000);

        $supervisor = $this->createUserForSetting(
            $setting,
            'POS CLOSE SUPERVISOR INVALID PIN',
            ['pos.access', 'pos.sessions.close', 'pos.supervisor.approval'],
            'close.supervisor.invalid.pin@example.com',
            'supervisor-secret'
        );

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), [
                'counted_cash_total' => 120000,
                'supervisor_identifier' => $supervisor->email,
                'supervisor_pin' => 'wrong-secret',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Supervisor approval failed for session close.',
                'requires_supervisor_approval' => true,
                'status' => 'CLOSING',
            ]);

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => 'CLOSING',
            'closed_at' => null,
            'active_marker' => 1,
        ]);

        $this->assertDatabaseMissing('pos_session_cash_events', [
            'pos_session_id' => $session->id,
            'event_type' => 'CLOSE_COUNT',
        ]);

        $this->assertDatabaseHas('pos_supervisor_approvals', [
            'setting_id' => $setting->id,
            'action_type' => 'SESSION_CLOSE_VARIANCE_APPROVAL',
            'target_type' => 'pos_session',
            'target_id' => $session->id,
            'requested_by' => $cashier->id,
            'approved_by' => null,
            'approval_result' => 'REJECTED',
            'reason' => 'INVALID_CREDENTIALS',
        ]);
    }

    public function test_close_variance_above_threshold_with_valid_supervisor_approval_closes_and_records_approval(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(100000, 1000);

        $supervisor = $this->createUserForSetting(
            $setting,
            'POS CLOSE SUPERVISOR APPROVED',
            ['pos.access', 'pos.sessions.close', 'pos.supervisor.approval'],
            'close.supervisor.approved@example.com',
            'supervisor-secret'
        );

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), [
                'counted_cash_total' => 120000,
                'counted_denominations' => [
                    '100000' => 1,
                    '20000' => 1,
                ],
                'notes' => 'Close with variance approved',
                'supervisor_identifier' => $supervisor->email,
                'supervisor_pin' => 'supervisor-secret',
            ]);

        $response->assertOk()
            ->assertJson([
                'session_id' => $session->id,
                'status' => 'CLOSED',
                'counted_cash_total' => 120000.0,
                'expected_cash_total' => 100000.0,
                'variance_total' => 20000.0,
                'variance_threshold' => 1000.0,
                'approval_result' => 'APPROVED',
            ])
            ->assertJsonPath('approval_id', fn ($value) => is_int($value) && $value > 0);

        $this->assertDatabaseHas('pos_supervisor_approvals', [
            'setting_id' => $setting->id,
            'action_type' => 'SESSION_CLOSE_VARIANCE_APPROVAL',
            'target_type' => 'pos_session',
            'target_id' => $session->id,
            'requested_by' => $cashier->id,
            'approved_by' => $supervisor->id,
            'approval_result' => 'APPROVED',
        ]);

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => 'CLOSED',
            'closed_by' => $cashier->id,
            'counted_cash_total' => 120000,
            'variance_total' => 20000,
            'close_approved_by' => $supervisor->id,
            'active_marker' => null,
        ]);

        $this->assertDatabaseHas('pos_session_cash_events', [
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'event_type' => 'CLOSE_COUNT',
            'direction' => 'NEUTRAL',
            'amount' => 120000,
            'performed_by' => $cashier->id,
            'approved_by' => $supervisor->id,
            'notes' => 'CLOSE WITH VARIANCE APPROVED',
        ]);
    }

    public function test_close_route_requires_pos_sessions_close_permission(): void
    {
        [$setting, $cashierWithoutClosePermission, $session] = $this->createOpenSession(
            100000,
            1000,
            ['pos.access', 'pos.sell', 'pos.sessions.open']
        );

        $this->actingAs($cashierWithoutClosePermission)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), [
                'counted_cash_total' => 100000,
            ])
            ->assertForbidden();
    }

    public function test_only_session_cashier_can_close_even_with_permission(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(100000, 1000);

        $otherCashier = $this->createUserForSetting(
            $setting,
            'POS CLOSE OTHER CASHIER',
            ['pos.access', 'pos.sell', 'pos.sessions.close']
        );

        $this->actingAs($otherCashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), [
                'counted_cash_total' => 100000,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => 'OPEN',
            'closed_at' => null,
        ]);
    }

    public function test_closed_session_cannot_transact_after_successful_close(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(100000, 5000);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), [
                'counted_cash_total' => 100000,
            ])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertForbidden();
    }

    private function createOpenSession(
        float $openingFloat,
        float $varianceThreshold,
        array $cashierPermissions = ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.sessions.close']
    ): array {
        $setting = $this->createSetting('BIZ CLOSE ' . $this->terminalSequence);
        $cashier = $this->createUserForSetting(
            $setting,
            'POS CLOSE CASHIER ' . $this->terminalSequence,
            $cashierPermissions
        );

        $terminal = $this->createTerminalForSetting($setting, $varianceThreshold);

        /** @var PosSessionLifecycleService $sessionLifecycleService */
        $sessionLifecycleService = app(PosSessionLifecycleService::class);

        $session = $sessionLifecycleService->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            $openingFloat,
            [(string) ((int) $openingFloat) => 1],
            $cashier->id
        );

        return [$setting, $cashier, $session];
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

    private function createUserForSetting(
        Setting $setting,
        string $roleName,
        array $permissions,
        ?string $email = null,
        ?string $plainPassword = null
    ): User {
        $role = Role::firstOrCreate(['name' => $roleName . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $attributes = [];

        if ($email !== null) {
            $attributes['email'] = $email;
        }

        if ($plainPassword !== null) {
            $attributes['password'] = Hash::make($plainPassword);
        }

        $user = User::factory()->create($attributes);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminalForSetting(Setting $setting, float $varianceThreshold): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'CLOSE COUNTER ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'CLOSE-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'Close Terminal ' . $sequence,
            'location_id' => $location->id,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => $varianceThreshold,
            'cash_threshold' => 100000,
            'require_pickup_supervisor_approval' => true,
        ]);

        return $terminal;
    }
}
