<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
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
class POSLiveSessionMonitorTest extends TestCase
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
            'pos.monitor.access',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_monitor_page_loads_for_authorized_user(): void
    {
        [$setting, , $session] = $this->createOpenSession(cashThreshold: 50000, openingFloat: 100000);
        
        $monitor = $this->createUserForSetting($setting, 'POS_MONITOR_ROLE', ['pos.access', 'pos.monitor.access']);

        $response = $this->actingAs($monitor)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.monitor.index'));

        $response->assertOk();
        $response->assertSee('Monitor Sesi POS Aktif');
    }

    public function test_monitor_page_blocked_for_unauthorized_user(): void
    {
        [$setting, , $session] = $this->createOpenSession(cashThreshold: 50000, openingFloat: 100000);
        
        $cashier = $this->createUserForSetting($setting, 'POS_CASHIER_ROLE', ['pos.access', 'pos.sell']);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.monitor.index'));

        $response->assertForbidden();
    }

    public function test_monitor_api_returns_active_sessions_with_expected_fields(): void
    {
        [$setting, $cashier1, $session1] = $this->createOpenSession(cashThreshold: 50000, openingFloat: 100000, terminalName: 'Terminal 1');
        [, $cashier2, $session2] = $this->createOpenSession(cashThreshold: 40000, openingFloat: 50000, terminalName: 'Terminal 2', setting: $setting);
        
        $monitor = $this->createUserForSetting($setting, 'POS_MONITOR_ROLE', ['pos.access', 'pos.monitor.access']);

        $response = $this->actingAs($monitor)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.monitor.sessions'));

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment([
                'session_id' => $session1->id,
                'status' => 'OPEN',
                'cashier_name' => strtoupper($cashier1->name),
                'terminal_name' => strtoupper('Terminal 1'),
                'expected_cash_total' => 100000,
                'is_threshold_breached' => true,
            ])
            ->assertJsonFragment([
                'session_id' => $session2->id,
                'status' => 'OPEN',
                'cashier_name' => strtoupper($cashier2->name),
                'terminal_name' => strtoupper('Terminal 2'),
                'expected_cash_total' => 50000,
                'is_threshold_breached' => true,
            ]);
    }

    public function test_threshold_breached_session_is_flagged_in_api_response(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(cashThreshold: 50000, openingFloat: 100000);
        
        $this->recordCashEvent($session, $cashier->id, 'CASH_SALE_IN', PosSessionCashEvent::DIRECTION_IN, 450000);
        app(PosSessionExpectedCashCalculator::class)->calculate($session->id);

        $monitor = $this->createUserForSetting($setting, 'POS_MONITOR_ROLE', ['pos.access', 'pos.monitor.access']);

        $response = $this->actingAs($monitor)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.monitor.sessions'));

        $response->assertOk()
            ->assertJsonFragment([
                'session_id' => $session->id,
                'expected_cash_total' => 550000,
                'threshold_value' => 50000,
                'is_threshold_breached' => true,
            ]);
    }

    public function test_closed_sessions_are_excluded_from_monitor(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(cashThreshold: 50000, openingFloat: 100000);
        
        $session->update([
            'status' => PosSession::STATUS_CLOSED,
            'active_marker' => null,
            'closed_at' => now(),
        ]);

        $monitor = $this->createUserForSetting($setting, 'POS_MONITOR_ROLE', ['pos.access', 'pos.monitor.access']);

        $response = $this->actingAs($monitor)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.monitor.sessions'));

        $response->assertOk()
            ->assertJsonCount(0);
    }

    public function test_safe_drops_and_last_activity_fields(): void
    {
        Carbon::setTestNow(now()->subMinutes(10));
        [$setting, $cashier, $session] = $this->createOpenSession(cashThreshold: 50000, openingFloat: 100000);
        $time1 = now()->addMinutes(2);
        
        $this->recordCashEvent($session, $cashier->id, 'CASH_SALE_IN', PosSessionCashEvent::DIRECTION_IN, 250000, $time1->toDateTimeString());
        
        $time2 = now()->addMinutes(5);
        $this->recordCashEvent($session, $cashier->id, 'SAFE_DROP_OUT', PosSessionCashEvent::DIRECTION_OUT, 100000, $time2->toDateTimeString());
        
        app(PosSessionExpectedCashCalculator::class)->calculate($session->id);

        $monitor = $this->createUserForSetting($setting, 'POS_MONITOR_ROLE', ['pos.access', 'pos.monitor.access']);

        $response = $this->actingAs($monitor)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.monitor.sessions'));

        /* 
         * The expected payload structure for counts and activity:
         */
        $response->assertOk();
        
        $data = $response->json()[0];
        $this->assertSame($session->id, $data['session_id']);
        $this->assertSame(250000, $data['expected_cash_total']);
        $this->assertSame(1, $data['safe_drops_count']);
        
        // Ensure last activity date is exactly $time2
        $this->assertTrue(Carbon::parse($data['last_activity_at'])->diffInSeconds($time2) < 2);
        
        Carbon::setTestNow(null);
    }

    private function createOpenSession(?float $cashThreshold, float $openingFloat, string $terminalName = 'Summary Terminal', ?Setting $setting = null): array
    {
        $setting = $setting ?? $this->createSetting('BIZ A');
        $cashier = $this->createUserForSetting(
            $setting,
            'POS CASHIER ' . uniqid(),
            ['pos.access', 'pos.sell', 'pos.sessions.open']
        );
        $terminal = $this->createTerminalForSetting($setting, $cashThreshold, $terminalName);

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

    private function createTerminalForSetting(Setting $setting, ?float $cashThreshold, string $terminalName): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'MONITOR LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'MONITOR-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => $terminalName,
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
