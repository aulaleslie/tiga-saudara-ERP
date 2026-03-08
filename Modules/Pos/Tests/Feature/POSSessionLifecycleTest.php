<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
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
class POSSessionLifecycleTest extends TestCase
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

        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.sell', 'web');
    }

    public function test_open_session_creates_open_session_for_valid_terminal_and_cashier(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, 'Cashier', ['pos.access', 'pos.sell']);
        $terminal = $this->createTerminalForSetting($setting);

        /** @var PosSessionLifecycleService $service */
        $service = app(PosSessionLifecycleService::class);
        $session = $service->openSession(
            $setting->id,
            $terminal->id,
            $user->id,
            100000,
            ['100000' => 1],
            $user->id
        );

        $this->assertSame('OPEN', $session->status);
        $this->assertSame($setting->id, (int) $session->setting_id);
        $this->assertSame($terminal->id, (int) $session->terminal_id);
        $this->assertSame($user->id, (int) $session->cashier_user_id);
        $this->assertSame(1, (int) $session->active_marker);

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => 'OPEN',
            'active_marker' => 1,
        ]);
    }

    public function test_open_session_rejects_duplicate_active_session_for_same_cashier_and_terminal(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, 'Cashier', ['pos.access', 'pos.sell']);
        $terminal = $this->createTerminalForSetting($setting);

        /** @var PosSessionLifecycleService $service */
        $service = app(PosSessionLifecycleService::class);
        $service->openSession($setting->id, $terminal->id, $user->id, 100000, ['100000' => 1], $user->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('active POS session');

        $service->openSession($setting->id, $terminal->id, $user->id, 100000, ['100000' => 1], $user->id);
    }

    public function test_session_lifecycle_allows_open_to_closing_to_closed_only(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, 'Cashier', ['pos.access', 'pos.sell']);
        $terminal = $this->createTerminalForSetting($setting);

        /** @var PosSessionLifecycleService $service */
        $service = app(PosSessionLifecycleService::class);
        $opened = $service->openSession($setting->id, $terminal->id, $user->id, 100000, ['100000' => 1], $user->id);

        $closing = $service->startClosing($opened->id, $user->id);
        $this->assertSame('CLOSING', $closing->status);
        $this->assertSame(1, (int) $closing->active_marker);

        $closed = $service->finalizeClosing($opened->id, $user->id, 100000, 'Closing note');

        $this->assertSame('CLOSED', $closed->status);
        $this->assertNull($closed->active_marker);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame($user->id, (int) $closed->closed_by);
    }

    public function test_session_lifecycle_rejects_invalid_status_transitions(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, 'Cashier', ['pos.access', 'pos.sell']);
        $terminal = $this->createTerminalForSetting($setting);

        /** @var PosSessionLifecycleService $service */
        $service = app(PosSessionLifecycleService::class);
        $opened = $service->openSession($setting->id, $terminal->id, $user->id, 100000, ['100000' => 1], $user->id);

        try {
            $service->finalizeClosing($opened->id, $user->id);
            $this->fail('Expected exception for invalid OPEN -> CLOSED transition.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('CLOSING', $exception->getMessage());
        }

        $closing = $service->startClosing($opened->id, $user->id);
        $this->assertSame('CLOSING', $closing->status);

        try {
            $service->startClosing($opened->id, $user->id);
            $this->fail('Expected exception for invalid CLOSING -> CLOSING transition.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('OPEN', $exception->getMessage());
        }

        $closed = $service->finalizeClosing($opened->id, $user->id);
        $this->assertSame('CLOSED', $closed->status);

        try {
            $service->startClosing($opened->id, $user->id);
            $this->fail('Expected exception for invalid CLOSED -> CLOSING transition.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('OPEN', $exception->getMessage());
        }
    }

    public function test_sell_route_redirects_to_session_open_without_active_session(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, 'Cashier', ['pos.access', 'pos.sell']);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertRedirect(route('pos.sessions.create'))
            ->assertSessionHas('warning', 'Active POS session is required before accessing POS sell screen.');
    }

    public function test_sell_route_allows_access_with_active_session(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, 'Cashier', ['pos.access', 'pos.sell']);
        $terminal = $this->createTerminalForSetting($setting);

        /** @var PosSessionLifecycleService $service */
        $service = app(PosSessionLifecycleService::class);
        $service->openSession($setting->id, $terminal->id, $user->id, 100000, ['100000' => 1], $user->id);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertOk()
            ->assertSee('Layar Kasir POS');
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
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'COUNTER-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'Terminal ' . $sequence,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'cash_threshold' => 50000,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
    }
}
