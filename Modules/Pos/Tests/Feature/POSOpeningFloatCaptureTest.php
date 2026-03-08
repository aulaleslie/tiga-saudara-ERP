<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
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
class POSOpeningFloatCaptureTest extends TestCase
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
            'pos.sell',
            'pos.sessions.open',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_it_opens_session_with_total_and_denominations_and_records_open_float_event(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting, allowTotalOnly: true);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_float_total' => '150000',
                'opening_denominations' => [
                    '100000' => 1,
                    '50000' => 1,
                ],
                'notes' => 'Shift pagi',
            ]);

        $response->assertRedirect(route('pos.sell'));

        $sessionId = (int) \DB::table('pos_sessions')
            ->where('setting_id', $setting->id)
            ->where('cashier_user_id', $user->id)
            ->value('id');

        $this->assertGreaterThan(0, $sessionId);

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $sessionId,
            'status' => 'OPEN',
            'opening_float_total' => 150000,
            'expected_cash_total' => 150000,
            'active_marker' => 1,
        ]);

        $this->assertDatabaseHas('pos_session_cash_events', [
            'setting_id' => $setting->id,
            'pos_session_id' => $sessionId,
            'event_type' => 'OPEN_FLOAT',
            'direction' => 'IN',
            'amount' => 150000,
            'performed_by' => $user->id,
            'notes' => 'SHIFT PAGI',
            'denominations' => null,
        ]);
    }

    public function test_it_allows_total_only_input_when_terminal_policy_allows_it(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting, allowTotalOnly: true);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_float_total' => '100000',
                'opening_denominations' => [],
            ]);

        $response->assertRedirect(route('pos.sell'));

        $sessionId = (int) \DB::table('pos_sessions')
            ->where('setting_id', $setting->id)
            ->where('cashier_user_id', $user->id)
            ->value('id');

        $this->assertDatabaseHas('pos_session_cash_events', [
            'pos_session_id' => $sessionId,
            'event_type' => 'OPEN_FLOAT',
            'amount' => 100000,
        ]);
    }

    public function test_it_rejects_when_opening_float_total_is_less_than_or_equal_to_cash_threshold(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting, true, 150000);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->from(route('pos.sessions.create'))
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_float_total' => '100000',
            ]);

        $response->assertRedirect(route('pos.sessions.create'));
        $response->assertSessionHasErrors(['opening_float_total']);

        $this->assertDatabaseCount('pos_sessions', 0);
    }

    public function test_it_rejects_when_cash_threshold_is_null(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting, true, null);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->from(route('pos.sessions.create'))
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_float_total' => '100000',
            ]);

        $response->assertRedirect(route('pos.sessions.create'));
        $response->assertSessionHasErrors(['terminal_id']);

        $this->assertDatabaseCount('pos_sessions', 0);
    }

    public function test_it_rejects_non_positive_opening_float_totals(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting, allowTotalOnly: true);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->from(route('pos.sessions.create'))
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_float_total' => '0',
                'opening_denominations' => [
                    '50000' => 1,
                ],
            ]);

        $response->assertRedirect(route('pos.sessions.create'));
        $response->assertSessionHasErrors(['opening_float_total']);

        $this->assertDatabaseCount('pos_sessions', 0);
    }

    public function test_open_session_routes_require_pos_sessions_open_permission(): void
    {
        $setting = $this->createSetting('BIZ A');
        Location::create([
            'name' => 'SESSION OPEN LOC ' . $setting->id,
            'setting_id' => $setting->id,
        ]);
        $userWithoutPermission = $this->createUserForSetting($setting, ['pos.access', 'pos.sell']);

        $this->actingAs($userWithoutPermission)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.create'))
            ->assertForbidden();

        $userWithPermission = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open']);

        $this->actingAs($userWithPermission)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.create'))
            ->assertOk()
            ->assertSee('Buka Sesi POS');
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
        $role = Role::firstOrCreate(['name' => 'POS CASHIER ' . $setting->id . '-' . count($permissions)]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminalForSetting(Setting $setting, bool $allowTotalOnly = true, ?float $cashThreshold = 50000): PosTerminal
    {
        $location = Location::create([
            'name' => 'COUNTER OPEN ' . $setting->id . '-' . ($allowTotalOnly ? 'Y' : 'N'),
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'OPEN-' . $setting->id . '-' . ($allowTotalOnly ? 'Y' : 'N'),
            'name' => 'Open Terminal ' . ($allowTotalOnly ? 'YES' : 'NO'),
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => $allowTotalOnly,
            'close_variance_approval_threshold' => 0,
            'cash_threshold' => $cashThreshold,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
    }
}
