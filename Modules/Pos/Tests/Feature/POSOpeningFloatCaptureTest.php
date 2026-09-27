<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
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
            'pos.sessions.require-terminal',
            'pos.transactions.view',
            'pos.transactions.save',
            'pos.transactions.load',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_it_opens_session_with_total_and_denominations_and_records_open_float_event(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.sessions.require-terminal',
        ]);
        $terminal = $this->createTerminalForSetting($setting, allowTotalOnly: true);
        $this->enablePaymentMethodForSetting($setting);

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
        $user = $this->createUserForSetting($setting, [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.sessions.require-terminal',
        ]);
        $terminal = $this->createTerminalForSetting($setting, allowTotalOnly: true);
        $this->enablePaymentMethodForSetting($setting);

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
        $this->markTestSkipped('Threshold validation removed - opening float no longer validated against cash_threshold');
    }

    public function test_it_rejects_when_cash_threshold_is_null(): void
    {
        $this->markTestSkipped('Threshold validation removed - cash_threshold is no longer required for session opening');
    }

    public function test_it_rejects_non_positive_opening_float_totals(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting, allowTotalOnly: true);
        $this->enablePaymentMethodForSetting($setting);

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
        $location = Location::create([
            'name' => 'SESSION OPEN LOC ' . $setting->id,
            'setting_id' => $setting->id,
        ]);
        $this->enableSaleLocationForSetting($setting, $location);
        $this->enablePaymentMethodForSetting($setting);

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

    public function test_all_users_can_open_without_terminal_and_float_is_optional(): void
    {
        $setting = $this->createSetting('BIZ TERMINAL OPTIONAL');
        $user = $this->createUserForSetting($setting, [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.sessions.require-terminal',
        ]);
        $this->createTerminalForSetting($setting, allowTotalOnly: true);
        $this->enablePaymentMethodForSetting($setting);

        // Terminal is now optional for all users, including those with pos.sessions.require-terminal
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('pos.sessions.store'), [
                'notes' => 'Non-terminal open session',
            ]);

        $response->assertRedirect(route('pos.sell'));
        $this->assertDatabaseHas('pos_sessions', [
            'setting_id' => $setting->id,
            'cashier_user_id' => $user->id,
            'terminal_id' => null,
            'opening_float_total' => 0,
            'expected_cash_total' => 0,
        ]);
    }

    public function test_floor_staff_open_session_form_hides_terminal_picker_and_explains_handoff_flow(): void
    {
        $setting = $this->createSetting('BIZ FLOOR STAFF OPEN');
        $user = $this->createUserForSetting($setting, [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ]);
        $this->createTerminalForSetting($setting, allowTotalOnly: true);
        $this->enablePaymentMethodForSetting($setting);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.create'))
            ->assertOk()
            ->assertSee('Terminal tidak dipakai untuk floor staff')
            ->assertSee('Floor staff bekerja tanpa terminal.')
            ->assertDontSee('Cari terminal...');
    }

    public function test_floor_staff_cannot_submit_terminal_selection_even_if_payload_is_crafted(): void
    {
        $setting = $this->createSetting('BIZ FLOOR STAFF NO TERMINAL PAYLOAD');
        $user = $this->createUserForSetting($setting, [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.view',
            'pos.transactions.save',
            'pos.transactions.load',
        ]);
        $terminal = $this->createTerminalForSetting($setting, allowTotalOnly: true);
        $this->enablePaymentMethodForSetting($setting);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->from(route('pos.sessions.create'))
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_float_total' => '100000',
            ])
            ->assertRedirect(route('pos.sessions.create'))
            ->assertSessionHasErrors(['terminal_id']);
    }

    public function test_user_can_open_with_terminal_and_opening_float(): void
    {
        $setting = $this->createSetting('BIZ WITH TERMINAL');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting, allowTotalOnly: true);
        $this->enablePaymentMethodForSetting($setting);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_float_total' => '100000',
                'notes' => 'Terminal session with float',
            ]);

        $response->assertRedirect(route('pos.sell'));
        $this->assertDatabaseHas('pos_sessions', [
            'setting_id' => $setting->id,
            'cashier_user_id' => $user->id,
            'terminal_id' => $terminal->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
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
        ]);

        return $terminal;
    }

    private function enablePaymentMethodForSetting(Setting $setting): void
    {
        $coa = \Modules\Setting\Entities\ChartOfAccount::create([
            'name' => 'Cash Account ' . $setting->id . '-' . bin2hex(random_bytes(4)),
            'account_number' => '1101-' . $setting->id . '-' . bin2hex(random_bytes(4)),
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $paymentMethod = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coa->id,
            'is_cash' => true,
        ]);

        \Modules\Setting\Entities\SettingPosPaymentMethod::updateOrCreate(
            [
                'setting_id' => $setting->id,
                'payment_method_id' => $paymentMethod->id,
            ],
            [
                'is_enabled' => true,
            ]
        );
    }

    private function enableSaleLocationForSetting(Setting $setting, Location $location): void
    {
        SettingSaleLocation::updateOrCreate(
            [
                'setting_id' => $setting->id,
                'location_id' => $location->id,
            ],
            [
                'is_enabled' => true,
                'position' => 1,
            ]
        );
    }
}
