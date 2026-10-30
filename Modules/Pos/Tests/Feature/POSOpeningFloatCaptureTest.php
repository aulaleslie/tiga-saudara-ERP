<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Setting\Entities\CashDenomination;
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

        $this->withoutVite();

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
            'pos.checkout.payment',
            'pos.checkout.debt',
            'pos.checkout.debt.request',
            'pos.sessions.open.total-only',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_it_opens_session_from_denomination_counts_and_records_open_float_event(): void
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
                // Tampered client total must be ignored in denomination mode.
                'opening_float_total' => '999999',
                'opening_denominations' => [
                    '100000' => 1,
                    '50000' => 1,
                    '1000' => '',
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
        ]);

        $event = PosSessionCashEvent::query()->where('pos_session_id', $sessionId)->firstOrFail();
        $this->assertSame(['100000' => 1, '50000' => 1], $event->denominations);
        $this->assertSame('denominations', $event->metadata['opening_cash_input_mode'] ?? null);
    }

    public function test_terminal_total_only_flag_does_not_authorize_total_only_input(): void
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

        foreach ([
            ['opening_float_total' => '100000', 'opening_denominations' => []],
            ['opening_float_total' => '100000', 'opening_cash_input_mode' => 'nominal'],
        ] as $payload) {
            $this->actingAs($user)
                ->withSession(['setting_id' => $setting->id])
                ->from(route('pos.sessions.create'))
                ->post(route('pos.sessions.store'), ['terminal_id' => $terminal->id] + $payload)
                ->assertRedirect(route('pos.sessions.create'))
                ->assertSessionHasErrors();
        }

        $this->assertDatabaseCount('pos_sessions', 0);
        $this->assertDatabaseCount('pos_session_cash_events', 0);
    }

    public function test_authorized_user_can_open_with_nominal_total_and_stale_counts_are_ignored(): void
    {
        $setting = $this->createSetting('BIZ NOMINAL');
        $user = $this->createUserForSetting($setting, [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.sessions.open.total-only',
        ]);
        $terminal = $this->createTerminalForSetting($setting, allowTotalOnly: false);
        $this->enablePaymentMethodForSetting($setting);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_cash_input_mode' => 'nominal',
                'opening_float_total' => '150000',
                'opening_denominations' => ['100000' => 5],
            ])
            ->assertRedirect(route('pos.sell'));

        $session = \Modules\Pos\Entities\PosSession::query()->where('cashier_user_id', $user->id)->firstOrFail();
        $this->assertEquals(150000, (float) $session->opening_float_total);
        $this->assertEquals(150000, (float) $session->expected_cash_total);

        $event = PosSessionCashEvent::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertNull($event->denominations);
        $this->assertSame('nominal', $event->metadata['opening_cash_input_mode'] ?? null);
        $this->assertSame((int) $user->id, (int) $event->performed_by);
    }

    public function test_authorized_user_defaults_to_denomination_mode_when_mode_omitted(): void
    {
        $setting = $this->createSetting('BIZ NOMINAL DEFAULT');
        $user = $this->createUserForSetting($setting, [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.sessions.open.total-only',
        ]);
        $terminal = $this->createTerminalForSetting($setting);
        $this->enablePaymentMethodForSetting($setting);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->from(route('pos.sessions.create'))
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_float_total' => '150000',
            ])
            ->assertRedirect(route('pos.sessions.create'))
            ->assertSessionHasErrors(['opening_denominations']);

        $this->assertDatabaseCount('pos_sessions', 0);
    }

    public function test_bypass_permission_alone_does_not_allow_opening_session(): void
    {
        $setting = $this->createSetting('BIZ BYPASS ONLY');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open.total-only']);
        $terminal = $this->createTerminalForSetting($setting);
        $this->enablePaymentMethodForSetting($setting);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_cash_input_mode' => 'nominal',
                'opening_float_total' => '150000',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('pos_sessions', 0);
    }

    /**
     * @dataProvider invalidCountProvider
     */
    public function test_it_rejects_invalid_denomination_counts(array $counts): void
    {
        $setting = $this->createSetting('BIZ INVALID COUNTS');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting);
        $this->enablePaymentMethodForSetting($setting);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->from(route('pos.sessions.create'))
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_denominations' => $counts,
            ])
            ->assertRedirect(route('pos.sessions.create'))
            ->assertSessionHasErrors(['opening_denominations']);

        $this->assertDatabaseCount('pos_sessions', 0);
        $this->assertDatabaseCount('pos_session_cash_events', 0);
    }

    public static function invalidCountProvider(): array
    {
        return [
            'negative' => [['50000' => '-1']],
            'fractional' => [['50000' => '1.5']],
            'nonnumeric' => [['50000' => 'abc']],
            'zero sum' => [['50000' => '0', '10000' => '']],
            'empty' => [[]],
            'unknown denomination' => [['12345' => '1']],
            'overflow' => [['100000' => '999999999999']],
        ];
    }

    public function test_it_rejects_disabled_denomination_submitted_from_stale_form(): void
    {
        $setting = $this->createSetting('BIZ STALE');
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting);
        $this->enablePaymentMethodForSetting($setting);

        CashDenomination::query()->where('value', 75000)->update(['is_enabled' => false]);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->from(route('pos.sessions.create'))
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_denominations' => ['75000' => '1', '50000' => '1'],
            ])
            ->assertRedirect(route('pos.sessions.create'))
            ->assertSessionHasErrors(['opening_denominations']);

        $this->assertDatabaseCount('pos_sessions', 0);
    }

    public function test_all_disabled_denominations_block_counted_opening_but_allow_authorized_nominal(): void
    {
        $setting = $this->createSetting('BIZ ALL DISABLED');
        $cashier = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting);
        $this->enablePaymentMethodForSetting($setting);

        CashDenomination::query()->update(['is_enabled' => false]);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.create'))
            ->assertOk()
            ->assertSee('Belum ada pecahan uang yang aktif');

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->from(route('pos.sessions.create'))
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_float_total' => '100000',
            ])
            ->assertSessionHasErrors(['opening_denominations']);

        $supervisor = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.sessions.open.total-only']);

        $this->actingAs($supervisor)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_cash_input_mode' => 'nominal',
                'opening_float_total' => '100000',
            ])
            ->assertRedirect(route('pos.sell'));

        $this->assertDatabaseCount('pos_sessions', 1);
    }

    public function test_open_form_renders_global_enabled_denominations_and_nominal_switch_only_for_permitted_users(): void
    {
        CashDenomination::query()->where('value', 75000)->update(['is_enabled' => false]);
        CashDenomination::query()->create(['value' => 150000, 'type' => 'banknote', 'is_enabled' => true, 'position' => 99]);
        $before = CashDenomination::query()->orderBy('id')->get(['value', 'is_enabled', 'position'])->toArray();

        foreach (['BIZ FORM A', 'BIZ FORM B'] as $name) {
            $setting = $this->createSetting($name);
            $location = Location::create(['name' => 'FORM LOC ' . $setting->id, 'setting_id' => $setting->id]);
            $this->enableSaleLocationForSetting($setting, $location);
            $this->enablePaymentMethodForSetting($setting);

            $cashier = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);

            $this->actingAs($cashier)
                ->withSession(['setting_id' => $setting->id])
                ->get(route('pos.sessions.create'))
                ->assertOk()
                ->assertSee('pos-open-workspace', false)
                ->assertSee('Hitung saldo awal')
                ->assertSee('Isi jumlah lembar atau keping. Kolom kosong dihitung nol.')
                ->assertSee('Ringkasan saldo awal')
                ->assertSee('Total saldo awal')
                ->assertSee('Tanpa terminal, pembayaran belum tersedia.')
                ->assertSee('name="opening_denominations[100000]"', false)
                ->assertSee('name="opening_denominations[150000]"', false)
                ->assertSee('name="opening_denominations[100]"', false)
                ->assertSee('aria-label="Jumlah Rp50.000, lembar"', false)
                ->assertSee('aria-label="Jumlah Rp100, keping"', false)
                ->assertDontSee('name="opening_denominations[75000]"', false)
                ->assertSee('lembar')
                ->assertSee('keping')
                ->assertSee('value="denominations"', false)
                ->assertDontSee('id="opening_cash_nominal_switch"', false);

            $supervisor = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.sessions.open.total-only']);

            $this->actingAs($supervisor)
                ->withSession(['setting_id' => $setting->id])
                ->get(route('pos.sessions.create'))
                ->assertOk()
                ->assertSee('id="opening_cash_nominal_switch"', false)
                ->assertSee('aria-pressed="false"', false)
                ->assertSee('Input nominal')
                ->assertSee('value="denominations"', false);
        }

        $this->assertSame($before, CashDenomination::query()->orderBy('id')->get(['value', 'is_enabled', 'position'])->toArray());
    }

    public function test_open_form_restores_old_mode_and_counts_after_validation_failure(): void
    {
        $setting = $this->createSetting('BIZ RESTORE');
        $terminal = $this->createTerminalForSetting($setting);
        $location = Location::create(['name' => 'RESTORE LOC ' . $setting->id, 'setting_id' => $setting->id]);
        $this->enableSaleLocationForSetting($setting, $location);
        $this->enablePaymentMethodForSetting($setting);

        $supervisor = $this->createUserForSetting($setting, [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.sessions.open.total-only',
        ]);

        // Denomination validation error restores submitted quantity.
        $this->actingAs($supervisor)
            ->withSession([
                'setting_id' => $setting->id,
                '_old_input' => [
                    'terminal_id' => $terminal->id,
                    'opening_cash_input_mode' => 'denominations',
                    'opening_denominations' => ['50000' => '3'],
                ],
            ])
            ->get(route('pos.sessions.create'))
            ->assertOk()
            ->assertSee('value="denominations"', false)
            ->assertSee('value="3"', false);

        // Nominal validation error restores nominal mode and formatted amount for permitted user.
        $this->actingAs($supervisor)
            ->withSession([
                'setting_id' => $setting->id,
                '_old_input' => [
                    'terminal_id' => $terminal->id,
                    'opening_cash_input_mode' => 'nominal',
                    'opening_float_total' => '250000',
                ],
            ])
            ->get(route('pos.sessions.create'))
            ->assertOk()
            ->assertSee('id="opening_cash_input_mode" value="nominal"', false)
            ->assertSee('value="250.000"', false);
    }

    public function test_open_form_renders_validation_feedback_on_redirect_when_non_scalar_inputs_are_submitted(): void
    {
        $setting = $this->createSetting('BIZ NON SCALAR RETRY');
        $terminal = $this->createTerminalForSetting($setting);
        $location = Location::create(['name' => 'NON SCALAR LOC ' . $setting->id, 'setting_id' => $setting->id]);
        $this->enableSaleLocationForSetting($setting, $location);
        $this->enablePaymentMethodForSetting($setting);

        $supervisor = $this->createUserForSetting($setting, [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.sessions.open.total-only',
        ]);

        // Nested array inside opening_denominations[50000][] must not crash the retry page.
        $this->actingAs($supervisor)
            ->withSession(['setting_id' => $setting->id])
            ->followingRedirects()
            ->from(route('pos.sessions.create'))
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_denominations' => ['50000' => ['1']],
            ])
            ->assertOk()
            ->assertSee('Jumlah pecahan uang harus berupa bilangan bulat tidak negatif.');

        // Array in opening_float_total[] in nominal mode must not crash the retry page.
        $this->actingAs($supervisor)
            ->withSession(['setting_id' => $setting->id])
            ->followingRedirects()
            ->from(route('pos.sessions.create'))
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_cash_input_mode' => 'nominal',
                'opening_float_total' => ['100000'],
            ])
            ->assertOk()
            ->assertSee('Total saldo awal wajib diisi dengan angka lebih dari nol.');
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
        $user = $this->createUserForSetting($setting, ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.sessions.open.total-only']);
        $terminal = $this->createTerminalForSetting($setting, allowTotalOnly: true);
        $this->enablePaymentMethodForSetting($setting);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->from(route('pos.sessions.create'))
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_cash_input_mode' => 'nominal',
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
            ->assertSee('Sesi tanpa terminal untuk menyiapkan dan menyerahkan draf.')
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
                'opening_denominations' => ['100000' => '1'],
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

    public function test_kasbon_only_user_with_draft_permissions_can_open_session_with_terminal(): void
    {
        $setting = $this->createSetting('BIZ KASBON ONLY TERMINAL');
        $user = $this->createUserForSetting($setting, [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.view',
            'pos.transactions.save',
            'pos.transactions.load',
            'pos.checkout.debt.request',
        ]);
        $terminal = $this->createTerminalForSetting($setting, allowTotalOnly: true);
        $this->enablePaymentMethodForSetting($setting);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('pos.sessions.store'), [
                'terminal_id' => $terminal->id,
                'opening_denominations' => ['50000' => '2'],
                'notes' => 'Kasbon-only terminal session',
            ])
            ->assertRedirect(route('pos.sell'));

        $this->assertDatabaseHas('pos_sessions', [
            'setting_id' => $setting->id,
            'cashier_user_id' => $user->id,
            'terminal_id' => $terminal->id,
            'opening_float_total' => 100000,
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
        $role = Role::firstOrCreate(['name' => 'POS CASHIER ' . $setting->id . '-' . md5(implode('|', $permissions))]);
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
            'name' => 'Cash ' . $setting->id,
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
