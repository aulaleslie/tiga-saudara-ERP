<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Mockery\MockInterface;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\Contracts\PosCashDrawerAdapter;
use Modules\Pos\Services\FinalizePosCheckoutService;
use Modules\Pos\Services\PosSafeDropService;
use Modules\Pos\Services\PosSessionCloseService;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSCashDrawerHookTest extends TestCase
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
            'pos.sessions.close',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_drawer_opens_on_session_open_if_configured(): void
    {
        $setting = $this->createSetting('BIZ 1');
        $terminal = $this->createTerminalForSetting($setting, [
            'auto_open_drawer_on_session_open' => true,
        ]);

        $this->mock(PosCashDrawerAdapter::class, function (MockInterface $mock) {
            $mock->shouldReceive('openDrawer')->once()->with(Mockery::on(function ($args) {
                return $args['trigger'] === 'session_open';
            }))->andReturn(['success' => true]);
        });

        $service = app(\Modules\Pos\Services\PosCashDrawerService::class);
        $result = $service->triggerDrawerOpen('session_open', $terminal->id, $setting->id);

        $this->assertTrue($result['triggered']);
        $this->assertTrue($result['success']);
    }

    public function test_drawer_does_not_open_on_session_open_if_disabled(): void
    {
        $setting = $this->createSetting('BIZ 2');
        $terminal = $this->createTerminalForSetting($setting, [
            'auto_open_drawer_on_session_open' => false,
        ]);

        $this->mock(PosCashDrawerAdapter::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('openDrawer');
        });

        $service = app(\Modules\Pos\Services\PosCashDrawerService::class);
        $result = $service->triggerDrawerOpen('session_open', $terminal->id, $setting->id);

        $this->assertFalse($result['triggered']);
        $this->assertTrue($result['success']);
    }

    public function test_drawer_failure_does_not_fail_session_open(): void
    {
        $setting = $this->createSetting('BIZ 3');
        $terminal = $this->createTerminalForSetting($setting, [
            'auto_open_drawer_on_session_open' => true,
        ]);

        $this->mock(PosCashDrawerAdapter::class, function (MockInterface $mock) {
            $mock->shouldReceive('openDrawer')->once()->andThrow(new DomainException('Simulated hardware failure'));
        });

        $service = app(\Modules\Pos\Services\PosCashDrawerService::class);
        $result = $service->triggerDrawerOpen('session_open', $terminal->id, $setting->id);

        $this->assertTrue($result['triggered']);
        $this->assertFalse($result['success']);
    }

    public function test_drawer_opens_on_cash_sale_if_configured(): void
    {
        $setting = $this->createSetting('BIZ 4');
        $terminal = $this->createTerminalForSetting($setting, [
            'auto_open_drawer_on_cash_sale' => true,
        ]);

        $this->mock(PosCashDrawerAdapter::class, function (MockInterface $mock) {
            $mock->shouldReceive('openDrawer')->once()->with(Mockery::on(function ($args) {
                return $args['trigger'] === 'cash_sale';
            }))->andReturn(['success' => true]);
        });

        $service = app(\Modules\Pos\Services\PosCashDrawerService::class);
        $result = $service->triggerDrawerOpen('cash_sale', $terminal->id, $setting->id);

        $this->assertTrue($result['triggered']);
        $this->assertTrue($result['success']);
    }

    public function test_drawer_opens_on_safe_drop_if_configured(): void
    {
        $setting = $this->createSetting('BIZ 5');
        $terminal = $this->createTerminalForSetting($setting, [
            'auto_open_drawer_on_pickup' => true,
        ]);

        $this->mock(PosCashDrawerAdapter::class, function (MockInterface $mock) {
            $mock->shouldReceive('openDrawer')->once()->with(Mockery::on(function ($args) {
                return $args['trigger'] === 'pickup';
            }))->andReturn(['success' => true]);
        });

        $service = app(\Modules\Pos\Services\PosCashDrawerService::class);
        $result = $service->triggerDrawerOpen('pickup', $terminal->id, $setting->id);

        $this->assertTrue($result['triggered']);
        $this->assertTrue($result['success']);
    }

    public function test_drawer_opens_on_session_close_if_configured(): void
    {
        $setting = $this->createSetting('BIZ 6');
        $terminal = $this->createTerminalForSetting($setting, [
            'auto_open_drawer_on_close' => true,
        ]);

        $this->mock(PosCashDrawerAdapter::class, function (MockInterface $mock) {
            $mock->shouldReceive('openDrawer')->once()->with(Mockery::on(function ($args) {
                return $args['trigger'] === 'close';
            }))->andReturn(['success' => true]);
        });

        $service = app(\Modules\Pos\Services\PosCashDrawerService::class);
        $result = $service->triggerDrawerOpen('close', $terminal->id, $setting->id);

        $this->assertTrue($result['triggered']);
        $this->assertTrue($result['success']);
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

    private function createTerminalForSetting(Setting $setting, array $policyOverrides = []): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'DRAWER TEST COUNTER ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'DRAWER-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'Drawer Test Terminal ' . $sequence,
            'location_id' => $location->id,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create(array_merge([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 1000000,
            'cash_threshold' => 1000000,
            'require_pickup_supervisor_approval' => false,
            'auto_open_drawer_on_session_open' => false,
            'auto_open_drawer_on_cash_sale' => false,
            'auto_open_drawer_on_pickup' => false,
            'auto_open_drawer_on_close' => false,
        ], $policyOverrides));

        return $terminal;
    }

    private function createActiveSession(int $settingId, int $terminalId, int $cashierId): PosSession
    {
        return PosSession::create([
            'setting_id' => $settingId,
            'terminal_id' => $terminalId,
            'cashier_user_id' => $cashierId,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opened_by' => $cashierId,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => PosSession::activeMarkerForStatus('OPEN'),
        ]);
    }
}
