<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSSafeDropWorkflowTest extends TestCase
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
            'pos.safeDrops.create',
            'pos.safeDrops.approve',
            'pos.supervisor.approval',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_safe_drop_success_with_valid_supervisor_credentials_records_event_and_updates_expected_cash(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(openingFloat: 150000, cashThreshold: 100000, requireSupervisorApproval: true);

        $supervisor = $this->createUserForSetting(
            $setting,
            'POS SAFE DROP SUPERVISOR',
            ['pos.access', 'pos.safeDrops.approve', 'pos.supervisor.approval'],
            'supervisor.safe.drop@example.com',
            'supervisor-secret'
        );

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.safe-drops.store', ['session' => $session->id]), [
                'amount' => 30000,
                'denominations' => [
                    '10000' => 3,
                ],
                'notes' => 'Drop to vault',
                'supervisor_identifier' => $supervisor->email,
                'supervisor_pin' => 'supervisor-secret',
            ]);

        $response->assertOk()
            ->assertJson([
                'session_id' => $session->id,
                'approval_result' => 'APPROVED',
                'expected_cash_before' => 150000.0,
                'expected_cash_after' => 120000.0,
                'dropped_amount' => 30000.0,
                'threshold_value' => 100000.0,
                'is_threshold_breached' => true,
            ])
            ->assertJsonStructure([
                'session_id',
                'cash_event_id',
                'approval_id',
                'approval_result',
                'expected_cash_before',
                'expected_cash_after',
                'dropped_amount',
                'threshold_value',
                'is_threshold_breached',
                'occurred_at',
            ]);

        $this->assertDatabaseHas('pos_session_cash_events', [
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'event_type' => 'SAFE_DROP_OUT',
            'direction' => 'OUT',
            'amount' => 30000,
            'performed_by' => $cashier->id,
            'approved_by' => $supervisor->id,
            'notes' => 'DROP TO VAULT',
        ]);

        $this->assertDatabaseHas('pos_supervisor_approvals', [
            'setting_id' => $setting->id,
            'action_type' => 'SAFE_DROP_APPROVAL',
            'target_type' => 'pos_session',
            'target_id' => $session->id,
            'requested_by' => $cashier->id,
            'approved_by' => $supervisor->id,
            'approval_result' => 'APPROVED',
        ]);

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'expected_cash_total' => 120000,
        ]);
    }

    public function test_safe_drop_rejected_on_invalid_supervisor_pin_without_cash_event_mutation(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(openingFloat: 120000, cashThreshold: 100000, requireSupervisorApproval: true);

        $supervisor = $this->createUserForSetting(
            $setting,
            'POS SAFE DROP SUPERVISOR INVALID PIN',
            ['pos.access', 'pos.safeDrops.approve', 'pos.supervisor.approval'],
            'supervisor.invalid.pin@example.com',
            'supervisor-secret'
        );

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.safe-drops.store', ['session' => $session->id]), [
                'amount' => 10000,
                'supervisor_identifier' => $supervisor->email,
                'supervisor_pin' => 'wrong-pin',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Supervisor approval failed for safe drop.',
            ]);

        $this->assertDatabaseMissing('pos_session_cash_events', [
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'event_type' => 'SAFE_DROP_OUT',
            'amount' => 10000,
        ]);

        $this->assertDatabaseHas('pos_supervisor_approvals', [
            'setting_id' => $setting->id,
            'action_type' => 'SAFE_DROP_APPROVAL',
            'target_type' => 'pos_session',
            'target_id' => $session->id,
            'requested_by' => $cashier->id,
            'approved_by' => null,
            'approval_result' => 'REJECTED',
            'reason' => 'INVALID_CREDENTIALS',
        ]);

        $expectedCash = (float) DB::table('pos_sessions')->where('id', $session->id)->value('expected_cash_total');
        $this->assertSame(120000.0, $expectedCash);
    }

    public function test_safe_drop_rejected_when_supervisor_missing_required_permissions(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(openingFloat: 120000, cashThreshold: 100000, requireSupervisorApproval: true);

        $supervisorWithoutPermission = $this->createUserForSetting(
            $setting,
            'POS SAFE DROP NO APPROVAL PERM',
            ['pos.access'],
            'supervisor.no.approval@example.com',
            'supervisor-secret'
        );

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.safe-drops.store', ['session' => $session->id]), [
                'amount' => 10000,
                'supervisor_identifier' => $supervisorWithoutPermission->email,
                'supervisor_pin' => 'supervisor-secret',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Supervisor approval failed for safe drop.',
            ]);

        $this->assertDatabaseMissing('pos_session_cash_events', [
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'event_type' => 'SAFE_DROP_OUT',
            'amount' => 10000,
        ]);

        $this->assertDatabaseHas('pos_supervisor_approvals', [
            'setting_id' => $setting->id,
            'action_type' => 'SAFE_DROP_APPROVAL',
            'target_type' => 'pos_session',
            'target_id' => $session->id,
            'requested_by' => $cashier->id,
            'approved_by' => null,
            'approval_result' => 'REJECTED',
            'reason' => 'MISSING_PERMISSION',
        ]);
    }

    public function test_safe_drop_rejected_when_amount_exceeds_expected_cash(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(openingFloat: 90000, cashThreshold: 50000, requireSupervisorApproval: true);

        $supervisor = $this->createUserForSetting(
            $setting,
            'POS SAFE DROP EXCESS SUPERVISOR',
            ['pos.access', 'pos.safeDrops.approve', 'pos.supervisor.approval'],
            'supervisor.excess.amount@example.com',
            'supervisor-secret'
        );

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.safe-drops.store', ['session' => $session->id]), [
                'amount' => 100000,
                'supervisor_identifier' => $supervisor->email,
                'supervisor_pin' => 'supervisor-secret',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Safe drop amount cannot exceed expected cash.',
            ]);

        $this->assertDatabaseMissing('pos_session_cash_events', [
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'event_type' => 'SAFE_DROP_OUT',
        ]);

        $this->assertDatabaseCount('pos_supervisor_approvals', 0);
    }

    public function test_safe_drop_route_requires_pos_safe_drops_create_permission(): void
    {
        [$setting, $cashierWithoutPermission, $session] = $this->createOpenSession(
            openingFloat: 150000,
            cashThreshold: 100000,
            requireSupervisorApproval: true,
            cashierPermissions: ['pos.access', 'pos.sell', 'pos.sessions.open']
        );

        $supervisor = $this->createUserForSetting(
            $setting,
            'POS SAFE DROP PERMISSION SUPERVISOR',
            ['pos.access', 'pos.safeDrops.approve', 'pos.supervisor.approval'],
            'supervisor.permission@example.com',
            'supervisor-secret'
        );

        $this->actingAs($cashierWithoutPermission)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.safe-drops.store', ['session' => $session->id]), [
                'amount' => 10000,
                'supervisor_identifier' => $supervisor->email,
                'supervisor_pin' => 'supervisor-secret',
            ])
            ->assertForbidden();
    }

    private function createOpenSession(
        float $openingFloat,
        ?float $cashThreshold,
        bool $requireSupervisorApproval,
        array $cashierPermissions = ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.safeDrops.create']
    ): array {
        $setting = $this->createSetting('BIZ SAFE DROP ' . $this->terminalSequence);
        $cashier = $this->createUserForSetting(
            $setting,
            'POS SAFE DROP CASHIER ' . $this->terminalSequence,
            $cashierPermissions
        );

        $terminal = $this->createTerminalForSetting($setting, $cashThreshold, $requireSupervisorApproval);

        $coaId = \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA PM ' . $setting->id,
            'account_number' => 'ACC-PM-' . $setting->id . '-' . rand(100, 999),
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $method = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        \Modules\Setting\Entities\SettingPosPaymentMethod::updateOrCreate(
            ['setting_id' => $setting->id, 'payment_method_id' => $method->id],
            ['is_enabled' => true]
        );

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

    private function createTerminalForSetting(
        Setting $setting,
        ?float $cashThreshold,
        bool $requireSupervisorApproval
    ): PosTerminal {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'SAFE DROP LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'SAFE-DROP-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'Safe Drop Terminal ' . $sequence,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'cash_threshold' => $cashThreshold,
            'require_pickup_supervisor_approval' => $requireSupervisorApproval,
        ]);

        return $terminal;
    }
}
