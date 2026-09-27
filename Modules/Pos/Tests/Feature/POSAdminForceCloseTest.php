<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Services\PosSessionAdminCloseService;
use Tests\TestCase;

class POSAdminForceCloseTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'Modules\User\Database\Seeders\PermissionsTableSeeder']);
    }

    /**
     * Test admin can force-close an OPEN session
     */
    public function test_admin_can_force_close_open_session(): void
    {
        $setting = $this->createSetting();
        $admin = $this->createUserWithPermission('pos.sessions.close-admin', $setting);
        $session = $this->createOpenSession($setting);

        $service = app(PosSessionAdminCloseService::class);
        $result = $service->closeSessionAsAdmin($setting->id, $session->id, $admin->id, 'Testing force close');

        $this->assertArrayHasKey('session_id', $result);
        $this->assertEquals($session->id, $result['session_id']);
        $this->assertEquals(PosSession::STATUS_CLOSED, $result['status']);
        $this->assertNotNull($result['closed_at']);

        $session->refresh();
        $this->assertEquals(PosSession::STATUS_CLOSED, $session->status);
        $this->assertEquals($admin->id, $session->closed_by);
        $this->assertNull($session->active_marker);
    }

    /**
     * Test admin close creates PosSessionCashEvent with EVENT_CLOSE_COUNT
     */
    public function test_admin_close_creates_cash_event(): void
    {
        $setting = $this->createSetting();
        $admin = $this->createUserWithPermission('pos.sessions.close-admin', $setting);
        $session = $this->createOpenSession($setting);

        $service = app(PosSessionAdminCloseService::class);
        $service->closeSessionAsAdmin($setting->id, $session->id, $admin->id);

        $event = PosSessionCashEvent::where('pos_session_id', $session->id)
            ->where('event_type', PosSessionCashEvent::EVENT_CLOSE_COUNT)
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals(PosSessionCashEvent::DIRECTION_NEUTRAL, $event->direction);
        $this->assertEquals(0, $event->amount);
        $this->assertEquals($admin->id, $event->performed_by);
    }

    /**
     * Test metadata stores closed_by_role: 'admin'
     */
    public function test_admin_close_stores_metadata(): void
    {
        $setting = $this->createSetting();
        $admin = $this->createUserWithPermission('pos.sessions.close-admin', $setting);
        $session = $this->createOpenSession($setting);

        $service = app(PosSessionAdminCloseService::class);
        $service->closeSessionAsAdmin($setting->id, $session->id, $admin->id, 'Test reason');

        $session->refresh();
        $this->assertIsArray($session->metadata);
        $this->assertEquals('admin', $session->metadata['closed_by_role']);
        $this->assertEquals('Test reason', $session->metadata['admin_close_reason']);
    }

    /**
     * Test user without pos.sessions.close-admin permission cannot force-close
     */
    public function test_user_without_permission_cannot_force_close(): void
    {
        $setting = $this->createSetting();
        $user = $this->createUser($setting);
        $session = $this->createOpenSession($setting);

        $service = app(PosSessionAdminCloseService::class);

        $this->expectException(\DomainException::class);
        $service->closeSessionAsAdmin($setting->id, $session->id, $user->id);
    }

    /**
     * Test force-close not available for non-OPEN sessions
     */
    public function test_cannot_force_close_non_open_session(): void
    {
        $setting = $this->createSetting();
        $admin = $this->createUserWithPermission('pos.sessions.close-admin', $setting);
        $session = $this->createOpenSession($setting);

        // First, close the session
        $session->update(['status' => PosSession::STATUS_CLOSED]);
        $session->refresh();

        $service = app(PosSessionAdminCloseService::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('must be in OPEN status');
        $service->closeSessionAsAdmin($setting->id, $session->id, $admin->id);
    }

    /**
     * Test force-close with invalid setting throws exception
     */
    public function test_force_close_with_invalid_setting(): void
    {
        $setting1 = $this->createSetting();
        $setting2 = $this->createSetting();
        $admin = $this->createUserWithPermission('pos.sessions.close-admin', $setting1);
        $session = $this->createOpenSession($setting1);

        $service = app(PosSessionAdminCloseService::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not assigned to current setting');
        $service->closeSessionAsAdmin($setting2->id, $session->id, $admin->id);
    }

    // Helper methods
    protected function createSetting()
    {
        return \Modules\Setting\Entities\Setting::create([
            'company_name' => 'Test Company ' . uniqid(),
            'company_email' => 'test' . uniqid() . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => \Modules\Currency\Entities\Currency::query()->value('id') ?? 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
        ]);
    }

    protected function createUser($setting = null)
    {
        $setting = $setting ?: $this->createSetting();
        $user = \App\Models\User::factory()->create([
            'email' => 'test' . uniqid() . '@example.com',
        ]);

        $role = \Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'user', 'guard_name' => 'web'],
        );
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    protected function createUserWithPermission($permission, $setting = null)
    {
        $user = $this->createUser($setting);

        $role = \Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
        );

        $permissionModel = \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => $permission, 'guard_name' => 'web'],
        );

        $role->givePermissionTo($permissionModel);
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
}
