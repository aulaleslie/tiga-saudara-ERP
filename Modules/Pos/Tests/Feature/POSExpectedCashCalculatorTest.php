<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionExpectedCashCalculator;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSExpectedCashCalculatorTest extends TestCase
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
            'pos.monitor.access',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_it_calculates_expected_cash_from_open_float_only_and_syncs_cache(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(cashThreshold: null, openingFloat: 120000);

        $session->update(['expected_cash_total' => 0]);

        /** @var PosSessionExpectedCashCalculator $calculator */
        $calculator = app(PosSessionExpectedCashCalculator::class);
        $result = $calculator->calculate($session->id);

        $this->assertSame($session->id, $result['session_id']);
        $this->assertSame(120000.0, (float) $result['expected_cash_total']);
        $this->assertSame(1, $result['events_count']);

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'setting_id' => $setting->id,
            'cashier_user_id' => $cashier->id,
            'expected_cash_total' => 120000,
        ]);
    }

    public function test_it_adds_in_events_for_expected_cash(): void
    {
        [, $cashier, $session] = $this->createOpenSession(cashThreshold: null, openingFloat: 100000);

        $this->recordCashEvent($session, $cashier->id, 'CASH_SALE_IN', PosSessionCashEvent::DIRECTION_IN, 25000);

        /** @var PosSessionExpectedCashCalculator $calculator */
        $calculator = app(PosSessionExpectedCashCalculator::class);
        $result = $calculator->calculate($session->id);

        $this->assertSame(125000.0, (float) $result['expected_cash_total']);
        $this->assertSame(2, $result['events_count']);
    }

    public function test_it_subtracts_out_events_for_expected_cash(): void
    {
        [, $cashier, $session] = $this->createOpenSession(cashThreshold: null, openingFloat: 100000);

        $this->recordCashEvent($session, $cashier->id, 'SAFE_DROP_OUT', PosSessionCashEvent::DIRECTION_OUT, 20000);

        /** @var PosSessionExpectedCashCalculator $calculator */
        $calculator = app(PosSessionExpectedCashCalculator::class);
        $result = $calculator->calculate($session->id);

        $this->assertSame(80000.0, (float) $result['expected_cash_total']);
        $this->assertSame(2, $result['events_count']);
    }

    public function test_it_handles_mixed_in_out_and_neutral_events_deterministically(): void
    {
        [, $cashier, $session] = $this->createOpenSession(cashThreshold: null, openingFloat: 100000);

        $this->recordCashEvent($session, $cashier->id, 'MANUAL_ADJUSTMENT_IN', PosSessionCashEvent::DIRECTION_IN, 10000, '2026-02-27 00:00:01');
        $this->recordCashEvent($session, $cashier->id, 'MANUAL_ADJUSTMENT_OUT', PosSessionCashEvent::DIRECTION_OUT, 3000, '2026-02-27 00:00:02');
        $this->recordCashEvent($session, $cashier->id, 'CLOSE_COUNT', PosSessionCashEvent::DIRECTION_NEUTRAL, 999, '2026-02-27 00:00:03');

        /** @var PosSessionExpectedCashCalculator $calculator */
        $calculator = app(PosSessionExpectedCashCalculator::class);
        $result = $calculator->calculate($session->id);

        $this->assertSame(107000.0, (float) $result['expected_cash_total']);
        $this->assertSame(4, $result['events_count']);
    }

    public function test_it_throws_for_unknown_direction_values(): void
    {
        [, $cashier, $session] = $this->createOpenSession(cashThreshold: null, openingFloat: 100000);

        $this->recordCashEvent($session, $cashier->id, 'BROKEN_EVENT', 'SIDEWAYS', 5000);

        /** @var PosSessionExpectedCashCalculator $calculator */
        $calculator = app(PosSessionExpectedCashCalculator::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Unknown cash event direction');

        $calculator->calculate($session->id);
    }

    public function test_summary_endpoint_uses_terminal_threshold_and_flags_breach(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(cashThreshold: 100000, openingFloat: 100000);
        $this->recordCashEvent($session, $cashier->id, 'CASH_SALE_IN', PosSessionCashEvent::DIRECTION_IN, 15000);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertOk()
            ->assertJson([
                'session_id' => $session->id,
                'cashier_user_id' => $cashier->id,
                'terminal_id' => $session->terminal_id,
                'threshold_source' => 'terminal_policy',
                'threshold_value' => 100000.0,
                'is_threshold_breached' => true,
            ]);
    }

    public function test_summary_endpoint_uses_default_threshold_when_terminal_threshold_null(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(cashThreshold: null, openingFloat: 100000);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertOk()
            ->assertJson([
                'session_id' => $session->id,
                'threshold_source' => 'default_config',
                'threshold_value' => 10000000.0,
                'is_threshold_breached' => false,
            ]);
    }

    public function test_summary_endpoint_allows_monitor_and_blocks_non_monitor_non_owner(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(cashThreshold: null, openingFloat: 100000);

        $monitor = $this->createUserForSetting(
            $setting,
            'POS MONITOR',
            ['pos.access', 'pos.monitor.access']
        );

        $nonMonitor = $this->createUserForSetting(
            $setting,
            'POS NON MONITOR',
            ['pos.access']
        );

        $this->actingAs($monitor)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.summary', ['session' => $session->id]))
            ->assertOk();

        $this->actingAs($nonMonitor)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.summary', ['session' => $session->id]))
            ->assertForbidden();
    }

    private function createOpenSession(?float $cashThreshold, float $openingFloat): array
    {
        $setting = $this->createSetting('BIZ A');
        $cashier = $this->createUserForSetting(
            $setting,
            'POS CASHIER',
            ['pos.access', 'pos.sell', 'pos.sessions.open']
        );
        $terminal = $this->createTerminalForSetting($setting, $cashThreshold);

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

    private function recordCashEvent(
        PosSession $session,
        int $performedBy,
        string $eventType,
        string $direction,
        float $amount,
        ?string $occurredAt = null
    ): void {
        PosSessionCashEvent::query()->create([
            'setting_id' => $session->setting_id,
            'pos_session_id' => $session->id,
            'event_type' => $eventType,
            'direction' => $direction,
            'amount' => $amount,
            'performed_by' => $performedBy,
            'occurred_at' => $occurredAt ? Carbon::parse($occurredAt) : now(),
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

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => $roleName . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminalForSetting(Setting $setting, ?float $cashThreshold): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'SUMMARY LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'SUMMARY-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'Summary Terminal ' . $sequence,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'cash_threshold' => $cashThreshold,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
    }
}
