<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Services\PosSessionExpectedCashCalculator;
use Modules\Pos\Services\PosSessionFinalizeService;
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Tests\TestCase;

class POSSessionFinalizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'Modules\User\Database\Seeders\PermissionsTableSeeder']);
    }

    /**
     * Test supervisor can finalize CLOSED session with variance within threshold
     */
    public function test_supervisor_can_finalize_closed_session_within_threshold(): void
    {
        $setting = $this->createSetting();
        $supervisor = $this->createUserWithPermissions(['pos.supervisor.approval'], $setting);
        $session = $this->createClosedSession($setting, 100000.00);

        $service = app(PosSessionFinalizeService::class);
        $result = $service->finalizeSession(
            $setting->id,
            $session->id,
            $supervisor->id,
            100000.00, // Exact amount - no variance
            'Testing finalization'
        );

        $this->assertFalse($result['blocked']);
        $this->assertArrayHasKey('payload', $result);
        $this->assertEquals($session->id, $result['payload']['session_id']);
        $this->assertEquals(PosSession::STATUS_FINALIZED, $result['payload']['status']);

        $session->refresh();
        $this->assertEquals(PosSession::STATUS_FINALIZED, $session->status);
        $this->assertNotNull($session->finalized_at);
    }

    /**
     * Test expected_cash_total calculation
     */
    public function test_expected_cash_calculation(): void
    {
        $setting = $this->createSetting();
        $session = $this->createClosedSession($setting, 150000.00);

        // Add cash sales
        $paymentMethod = $this->createCashPaymentMethod($setting);
        PosCheckout::create([
            'pos_session_id' => $session->id,
            'setting_id' => $setting->id,
            'payment_method_id' => $paymentMethod->id,
            'grand_total' => 50000.00,
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        $calculator = app(PosSessionExpectedCashCalculator::class);
        $result = $calculator->calculate($session->id);

        $expectedCash = 150000.00 + 50000.00; // opening + cash_sales
        $this->assertEquals($expectedCash, $result['expected_cash_total']);
    }

    /**
     * Test variance calculation: actual - expected
     */
    public function test_variance_calculation(): void
    {
        $setting = $this->createSetting();
        $supervisor = $this->createUserWithPermissions(['pos.supervisor.approval'], $setting);
        $session = $this->createClosedSession($setting, 100000.00);

        $service = app(PosSessionFinalizeService::class);
        $result = $service->finalizeSession(
            $setting->id,
            $session->id,
            $supervisor->id,
            105000.00 // 5000 over
        );

        $this->assertEquals(5000.00, $result['payload']['variance_total']);
    }

    /**
     * Test finalization creates PosSessionCashEvent with EVENT_FINALIZE_COUNT
     */
    public function test_finalization_creates_cash_event(): void
    {
        $setting = $this->createSetting();
        $supervisor = $this->createUserWithPermissions(['pos.supervisor.approval'], $setting);
        $session = $this->createClosedSession($setting, 100000.00);

        $service = app(PosSessionFinalizeService::class);
        $service->finalizeSession($setting->id, $session->id, $supervisor->id, 100000.00);

        $event = PosSessionCashEvent::where('pos_session_id', $session->id)
            ->where('event_type', PosSessionCashEvent::EVENT_FINALIZE_COUNT)
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals(PosSessionCashEvent::DIRECTION_NEUTRAL, $event->direction);
        $this->assertEquals(100000.00, $event->amount);
        $this->assertEquals($supervisor->id, $event->performed_by);
    }

    /**
     * Test finalization blocked if variance > threshold and user lacks pos.sessions.approve-variance
     */
    public function test_finalization_blocked_if_variance_exceeds_threshold_without_approval(): void
    {
        $setting = $this->createSetting();
        $supervisor = $this->createUserWithPermissions(['pos.supervisor.approval'], $setting);
        $session = $this->createClosedSession($setting, 100000.00, 5000.00); // threshold 5000

        $service = app(PosSessionFinalizeService::class);
        $result = $service->finalizeSession(
            $setting->id,
            $session->id,
            $supervisor->id,
            120000.00 // variance of 20000 > threshold of 5000
        );

        $this->assertTrue($result['blocked']);
        $this->assertTrue($result['payload']['requires_variance_approval']);
        $this->assertEquals(20000.00, $result['payload']['variance_total']);

        $session->refresh();
        $this->assertEquals(PosSession::STATUS_CLOSED, $session->status); // Should still be CLOSED
    }

    /**
     * Test supervisor with pos.sessions.approve-variance can approve variance
     */
    public function test_supervisor_with_variance_approval_can_finalize(): void
    {
        $setting = $this->createSetting();
        $supervisor = $this->createUserWithPermissions(
            ['pos.supervisor.approval', 'pos.sessions.approve-variance'],
            $setting
        );
        $session = $this->createClosedSession($setting, 100000.00, 5000.00);

        $service = app(PosSessionFinalizeService::class);
        $result = $service->finalizeSession(
            $setting->id,
            $session->id,
            $supervisor->id,
            120000.00 // variance of 20000 > threshold, but user has approval permission
        );

        $this->assertFalse($result['blocked']);
        $this->assertEquals(PosSession::STATUS_FINALIZED, $result['payload']['status']);

        $session->refresh();
        $this->assertEquals(PosSession::STATUS_FINALIZED, $session->status);
    }

    /**
     * Test user without pos.supervisor.approval cannot finalize
     */
    public function test_user_without_supervisor_approval_cannot_finalize(): void
    {
        $setting = $this->createSetting();
        $user = $this->createUser($setting);
        $session = $this->createClosedSession($setting);

        $service = app(PosSessionFinalizeService::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not assigned to current setting');
        $service->finalizeSession($setting->id, $session->id, $user->id, 100000.00);
    }

    /**
     * Test finalization not available for OPEN or FINALIZED sessions
     */
    public function test_cannot_finalize_non_closed_session(): void
    {
        $setting = $this->createSetting();
        $supervisor = $this->createUserWithPermissions(['pos.supervisor.approval'], $setting);

        // Test OPEN session
        $openSession = $this->createOpenSession($setting);
        $service = app(PosSessionFinalizeService::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('must be in CLOSED status');
        $service->finalizeSession($setting->id, $openSession->id, $supervisor->id, 100000.00);
    }

    /**
     * Test FINALIZED session cannot be modified further
     */
    public function test_cannot_finalize_finalized_session(): void
    {
        $setting = $this->createSetting();
        $supervisor = $this->createUserWithPermissions(['pos.supervisor.approval'], $setting);
        $session = $this->createClosedSession($setting);

        // First finalize
        $service = app(PosSessionFinalizeService::class);
        $service->finalizeSession($setting->id, $session->id, $supervisor->id, 100000.00);

        // Try to finalize again
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('must be in CLOSED status');
        $service->finalizeSession($setting->id, $session->id, $supervisor->id, 100000.00);
    }

    /**
     * Test actual_cash_received validation
     */
    public function test_negative_actual_cash_is_rejected(): void
    {
        $setting = $this->createSetting();
        $supervisor = $this->createUserWithPermissions(['pos.supervisor.approval'], $setting);
        $session = $this->createClosedSession($setting);

        $service = app(PosSessionFinalizeService::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('must be at least zero');
        $service->finalizeSession($setting->id, $session->id, $supervisor->id, -100.00);
    }

    // Helper methods
    protected function createSetting()
    {
        return \Modules\Setting\Entities\Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => \Modules\Currency\Entities\Currency::query()->value('id') ?? 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
        ]);
    }

    protected function createUser($setting = null)
    {
        $setting = $setting ?: $this->createSetting();
        $user = \App\Models\User::factory()->create([
            'email' => 'test' . uniqid() . '@example.com',
        ]);

        // Create a basic role
        $role = \Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'user', 'guard_name' => 'web'],
        );

        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    protected function createUserWithPermissions($permissions, $setting = null)
    {
        $user = $this->createUser($setting);

        $role = \Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'supervisor', 'guard_name' => 'web'],
        );

        foreach ($permissions as $permission) {
            $permissionModel = \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
            );
            $role->givePermissionTo($permissionModel);
        }

        $user->assignRole($role);

        return $user;
    }

    protected function createOpenSession($setting)
    {
        $cashier = $this->createUser($setting);
        $terminal = \Modules\Pos\Entities\PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'TERM-' . uniqid(),
            'name' => 'Terminal ' . uniqid(),
            'is_active' => true,
        ]);

        return PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 100000.00,
        ]);
    }

    protected function createClosedSession($setting, $expectedCash = 100000.00, $varianceThreshold = 10000.00)
    {
        $cashier = $this->createUser($setting);
        $terminal = \Modules\Pos\Entities\PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'TERM-' . uniqid(),
            'name' => 'Terminal ' . uniqid(),
            'is_active' => true,
        ]);

        // Create terminal policy
        \Modules\Pos\Entities\PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'close_variance_approval_threshold' => $varianceThreshold,
        ]);

        $session = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_CLOSED,
            'opened_at' => now()->subHours(2),
            'closed_at' => now(),
            'opened_by' => $cashier->id,
            'closed_by' => $cashier->id,
            'opening_float_total' => $expectedCash,
            'expected_cash_total' => $expectedCash,
        ]);

        // Create initial cash event for opening float
        PosSessionCashEvent::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'event_type' => PosSessionCashEvent::EVENT_OPEN_FLOAT,
            'direction' => PosSessionCashEvent::DIRECTION_IN,
            'amount' => $expectedCash,
            'performed_by' => $cashier->id,
            'occurred_at' => now()->subHours(2),
        ]);

        return $session;
    }

    protected function createCashPaymentMethod($setting)
    {
        return SettingPosPaymentMethod::create([
            'setting_id' => $setting->id,
            'code' => 'CASH',
            'name' => 'Tunai',
            'is_cash' => true,
            'is_active' => true,
        ]);
    }
}
