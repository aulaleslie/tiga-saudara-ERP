<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionExpectedCashCalculator;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosExpectedCashVisibilityTest extends TestCase
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
            'pos.sessions.view',
            'pos.sessions.viewExpectedCash',
            'pos.sessions.close',
            'pos.sessions.approve-variance',
            'pos.safeDrops.create',
            'pos.safeDrops.approve',
            'pos.supervisor.approval',
            'pos.reconciliation.access',
            'pos.reports.access',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_permissions_sync_grants_admin_and_not_ordinary_roles_and_super_admin_bypasses(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'Admin']);
        $ordinaryRole = Role::firstOrCreate(['name' => 'Cashier Ordinary']);
        $ordinaryRole->syncPermissions(['pos.access', 'pos.sell', 'pos.sessions.view']);

        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin']);

        $this->artisan('permissions:sync')->assertSuccessful();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->assertTrue($adminRole->fresh()->hasPermissionTo('pos.sessions.viewExpectedCash'));
        $this->assertFalse($ordinaryRole->fresh()->hasPermissionTo('pos.sessions.viewExpectedCash'));

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);
        $this->assertTrue($superAdmin->can('pos.sessions.viewExpectedCash'));

        // Revocation changes subsequent disclosure
        $setting = $this->createSetting('BIZ SYNC');
        $terminal = $this->createTerminal($setting);
        $user = $this->createUserForSetting($setting, 'Revocable Role', [
            'pos.access',
            'pos.sessions.view',
            'pos.sessions.viewExpectedCash',
        ]);
        $session = $this->createSession($setting, $terminal, $user, 950000);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]))
            ->assertOk()
            ->assertJsonPath('can_view_expected_cash', true)
            ->assertJsonPath('expected_cash_total', 950000);

        $role = Role::where('name', 'Revocable Role')->firstOrFail();
        $role->revokePermissionTo('pos.sessions.viewExpectedCash');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->actingAs($user->fresh())
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]))
            ->assertOk()
            ->assertJsonPath('can_view_expected_cash', false)
            ->assertJsonMissingPath('expected_cash_total')
            ->assertJsonMissingPath('cash_events');
    }

    public function test_visibility_does_not_bypass_session_or_business_authorization(): void
    {
        $settingA = $this->createSetting('BIZ A');
        $settingB = $this->createSetting('BIZ B');
        $terminalB = $this->createTerminal($settingB);
        $ownerB = $this->createUserForSetting($settingB, 'Owner B', ['pos.access']);
        $sessionB = $this->createSession($settingB, $terminalB, $ownerB, 500000);

        // User in setting A has viewExpectedCash + pos.sessions.view, but cannot see setting B session
        $viewerA = $this->createUserForSetting($settingA, 'Viewer A', [
            'pos.access',
            'pos.sessions.view',
            'pos.sessions.viewExpectedCash',
        ]);

        $this->actingAs($viewerA)
            ->withSession(['setting_id' => $settingA->id])
            ->getJson(route('pos.sessions.summary', ['session' => $sessionB->id]))
            ->assertNotFound();

        // User in setting B has viewExpectedCash but NOT pos.sessions.view and is NOT owner
        $otherB = $this->createUserForSetting($settingB, 'Other B', [
            'pos.access',
            'pos.sessions.viewExpectedCash',
        ]);

        $this->actingAs($otherB)
            ->withSession(['setting_id' => $settingB->id])
            ->getJson(route('pos.sessions.summary', ['session' => $sessionB->id]))
            ->assertForbidden();
    }

    public function test_session_index_and_reconciliation_hide_protected_values_without_permission(): void
    {
        $setting = $this->createSetting('BIZ VISIBILITY');
        $terminal = $this->createTerminal($setting);
        $cashier = $this->createUserForSetting($setting, 'Cashier Hidden', [
            'pos.access',
            'pos.sessions.view',
            'pos.reconciliation.access',
        ]);
        $session = $this->createSession($setting, $terminal, $cashier, 950000, PosSession::STATUS_CLOSED);

        // Sessions index HTML hides Kas, Saldo Awal, Pengambilan Kas, Threshold
        $indexResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index'));

        $indexResponse->assertOk()
            ->assertDontSee('950.000')
            ->assertDontSee('Saldo Awal');

        // Reconciliation JSON omits protected fields
        $reconResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reconciliation.sessions', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]));

        $reconResponse->assertOk()
            ->assertJsonPath('0.can_view_expected_cash', false)
            ->assertJsonMissingPath('0.expected_cash')
            ->assertJsonMissingPath('0.opening_float')
            ->assertJsonMissingPath('0.variance')
            ->assertJsonMissingPath('0.safe_drop_total');
    }

    public function test_integrated_otp_pickup_exceeding_expected_cash_yields_negative_balance_and_respects_visibility(): void
    {
        $setting = $this->createSetting('BIZ NEGATIVE PICKUP');
        $terminal = $this->createTerminal($setting);

        $cashierHidden = $this->createUserForSetting($setting, 'Cashier Blind Pickup', [
            'pos.access',
            'pos.sell',
        ]);

        $viewerAuthorized = $this->createUserForSetting($setting, 'Viewer Authorized', [
            'pos.access',
            'pos.sessions.view',
            'pos.sessions.viewExpectedCash',
        ]);

        $supervisor = $this->createUserForSetting($setting, 'Pickup Supervisor', [
            'pos.access',
            'pos.safeDrops.approve',
            'pos.supervisor.approval',
        ]);

        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->generateSecretKey();
        $twoFactor->enableTwoFactor($supervisor, $secret);
        $google2fa = new \PragmaRX\Google2FA\Google2FA();

        $session = $this->createSession($setting, $terminal, $cashierHidden, 950000);

        $otp = $google2fa->getCurrentOtp($secret);

        // 1. Unauthorized cashier submits Rp 1,000,000 pickup against Rp 950,000 expected cash
        $pickupResponse = $this->actingAs($cashierHidden)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.pickup', ['session' => $session->id]), [
                'amount' => 1000000,
                'supervisor_id' => $supervisor->id,
                'otp_code' => $otp,
            ]);

        $pickupResponse->assertOk()
            ->assertJson([
                'message' => 'Pengambilan kas berhasil.',
                'can_view_expected_cash' => false,
            ])
            ->assertJsonMissingPath('expected_cash_after');

        // 2. Verify signed persistence and ledger recalculation without zero floor
        $session->refresh();
        $this->assertSame(-50000.0, (float) $session->expected_cash_total);

        $recalculated = app(PosSessionExpectedCashCalculator::class)->calculate($session->id);
        $this->assertSame(-50000.0, (float) $recalculated['expected_cash_total']);
        $this->assertSame(-50000.0, (float) $recalculated['cached_expected_cash_total']);

        // 3. Authorized viewer sees -50000 in JSON summary
        $this->actingAs($viewerAuthorized)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]))
            ->assertOk()
            ->assertJsonPath('can_view_expected_cash', true)
            ->assertJsonPath('expected_cash_total', -50000);

        // 4. Unauthorized cashier sees no protected balance in JSON or HTML summary
        $this->actingAs($cashierHidden)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]))
            ->assertOk()
            ->assertJsonPath('can_view_expected_cash', false)
            ->assertJsonMissingPath('expected_cash_total')
            ->assertJsonMissingPath('cached_expected_cash_total');

        // 5. Pickup from already-negative balance (-50000 - 25000 = -75000) succeeds for authorized actor too
        $secondPickup = $this->actingAs($viewerAuthorized)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.pickup', ['session' => $session->id]), [
                'amount' => 25000,
                'supervisor_id' => $supervisor->id,
                'otp_code' => $google2fa->getCurrentOtp($secret),
            ]);

        $secondPickup->assertOk()
            ->assertJsonPath('can_view_expected_cash', true)
            ->assertJsonPath('expected_cash_after', -75000);
    }

    public function test_blind_finalization_hides_variance_and_retains_internal_audit_and_approval_gates(): void
    {
        $setting = $this->createSetting('BIZ BLIND FINALIZE');
        $terminal = $this->createTerminal($setting, closeVarianceThreshold: 10000);

        // Supervisor without pos.sessions.viewExpectedCash and without approve-variance
        $blindSupervisor = $this->createUserForSetting($setting, 'Blind Supervisor', [
            'pos.access',
            'pos.supervisor.approval',
            'pos.sessions.view',
        ]);

        // Approver WITH viewExpectedCash and approve-variance
        $approver = $this->createUserForSetting($setting, 'Variance Approver', [
            'pos.access',
            'pos.supervisor.approval',
            'pos.sessions.approve-variance',
            'pos.sessions.viewExpectedCash',
        ], 'approver@example.com', 'override-secret');

        $session = $this->createSession($setting, $terminal, $blindSupervisor, 500000, PosSession::STATUS_CLOSED);

        // 1. Exceeds threshold (actual 400000 vs expected 500000 -> -100000) => 422 requires_variance_approval WITHOUT numeric variance
        $blockedResponse = $this->actingAs($blindSupervisor)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.finalize', ['session' => $session->id]), [
                'actual_cash_received' => 400000,
            ]);

        $blockedResponse->assertStatus(422)
            ->assertJsonPath('requires_variance_approval', true)
            ->assertJsonPath('can_view_expected_cash', false)
            ->assertJsonMissingPath('variance_total')
            ->assertJsonMissingPath('variance_threshold')
            ->assertJsonMissingPath('expected_cash_total');

        // 2. Submit with valid approver credentials => succeeds, approver's visibility does NOT reveal balances to requester
        $approvedResponse = $this->actingAs($blindSupervisor)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.finalize', ['session' => $session->id]), [
                'actual_cash_received' => 400000,
                'supervisor_identifier' => $approver->email,
                'supervisor_password' => 'override-secret',
            ]);

        $approvedResponse->assertOk()
            ->assertJsonPath('status', PosSession::STATUS_FINALIZED)
            ->assertJsonPath('approval_result', 'APPROVED')
            ->assertJsonPath('can_view_expected_cash', false)
            ->assertJsonMissingPath('expected_cash_total')
            ->assertJsonMissingPath('variance_total')
            ->assertJsonMissingPath('variance_threshold');

        // 3. Internal audit event still records full metadata
        $event = PosSessionCashEvent::query()
            ->where('pos_session_id', $session->id)
            ->where('event_type', PosSessionCashEvent::EVENT_FINALIZE_COUNT)
            ->firstOrFail();

        $this->assertSame(500000.0, (float) $event->metadata['expected_cash_total']);
        $this->assertSame(-100000.0, (float) $event->metadata['variance_total']);
        $this->assertSame(10000.0, (float) $event->metadata['variance_threshold']);
        $this->assertSame($approver->id, (int) $event->metadata['approver_id']);
    }

    public function test_pos_reports_withhold_cash_components_without_visibility_and_disclose_with_visibility(): void
    {
        $setting = $this->createSetting('BIZ REPORTS VISIBILITY');
        $terminal = $this->createTerminal($setting);

        $reportViewerHidden = $this->createUserForSetting($setting, 'Report Viewer Hidden', [
            'pos.access',
            'pos.reports.access',
        ]);

        $reportViewerAuthorized = $this->createUserForSetting($setting, 'Report Viewer Authorized', [
            'pos.access',
            'pos.reports.access',
            'pos.sessions.viewExpectedCash',
        ]);

        $session = $this->createSession($setting, $terminal, $reportViewerHidden, 0);

        $coaId = \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Cash COA Report',
            'account_number' => 'ACC-REPORT-CASH',
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cashMethod = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'CASH',
            'is_cash' => true,
            'coa_id' => $coaId,
        ]);

        $checkout = \Modules\Pos\Entities\PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $reportViewerHidden->id,
            'status' => \Modules\Pos\Entities\PosCheckout::STATUS_POSTED,
            'receipt_number' => 'RCP-RPT-001',
            'subtotal' => 250000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 250000,
            'change_total' => 0,
            'finalized_at' => now(),
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'payload_hash' => hash('sha256', 'report-visibility-test'),
            'payment_method_id' => $cashMethod->id,
        ]);

        \Modules\Pos\Entities\PosCheckoutPayment::create([
            'pos_checkout_id' => $checkout->id,
            'payment_method_id' => $cashMethod->id,
            'amount_minor_units' => 25000000,
            'sequence_order' => 1,
        ]);

        $dateParams = [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ];

        // 1. User WITHOUT pos.sessions.viewExpectedCash
        $this->actingAs($reportViewerHidden)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.reports.index'))
            ->assertOk()
            ->assertDontSee('Rasio Tunai')
            ->assertDontSee('id="tab-payments"', false);

        $this->actingAs($reportViewerHidden)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.daily-sales', $dateParams))
            ->assertOk()
            ->assertJsonPath('0.grand_total', 250000)
            ->assertJsonPath('0.can_view_expected_cash', false)
            ->assertJsonMissingPath('0.cash_total')
            ->assertJsonMissingPath('0.non_cash_total');

        $this->actingAs($reportViewerHidden)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.cashier-summary', $dateParams))
            ->assertOk()
            ->assertJsonPath('0.grand_total', 250000)
            ->assertJsonPath('0.can_view_expected_cash', false)
            ->assertJsonMissingPath('0.cash_total')
            ->assertJsonMissingPath('0.non_cash_total');

        $this->actingAs($reportViewerHidden)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.payment-methods', $dateParams))
            ->assertOk()
            ->assertExactJson([]);

        // 2. User WITH pos.sessions.viewExpectedCash
        $this->actingAs($reportViewerAuthorized)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.reports.index'))
            ->assertOk()
            ->assertSee('Rasio Tunai')
            ->assertSee('id="tab-payments"', false);

        $this->actingAs($reportViewerAuthorized)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.daily-sales', $dateParams))
            ->assertOk()
            ->assertJsonPath('0.can_view_expected_cash', true)
            ->assertJsonPath('0.cash_total', 250000)
            ->assertJsonPath('0.non_cash_total', 0);

        $this->actingAs($reportViewerAuthorized)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.cashier-summary', $dateParams))
            ->assertOk()
            ->assertJsonPath('0.can_view_expected_cash', true)
            ->assertJsonPath('0.cash_total', 250000)
            ->assertJsonPath('0.non_cash_total', 0);

        $this->actingAs($reportViewerAuthorized)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.payment-methods', $dateParams))
            ->assertOk()
            ->assertJsonPath('0.payment_method_label', 'CASH')
            ->assertJsonPath('0.grand_total', 250000);
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

    private function createTerminal(Setting $setting, float $closeVarianceThreshold = 0): PosTerminal
    {
        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'TERM-' . $setting->id . '-' . rand(100, 999),
            'name' => 'Terminal Test',
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => $closeVarianceThreshold,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 1000000,
        ]);

        return $terminal;
    }

    private function createUserForSetting(
        Setting $setting,
        string $roleName,
        array $permissions,
        ?string $email = null,
        ?string $password = null
    ): User {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->syncPermissions($permissions);

        $attributes = [];
        if ($email !== null) {
            $attributes['email'] = $email;
        }
        if ($password !== null) {
            $attributes['password'] = bcrypt($password);
        }

        $user = User::factory()->create($attributes);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createSession(
        Setting $setting,
        PosTerminal $terminal,
        User $cashier,
        float $openingFloat,
        string $status = PosSession::STATUS_OPEN
    ): PosSession {
        $session = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => $status,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'closed_at' => $status === PosSession::STATUS_CLOSED ? now() : null,
            'closed_by' => $status === PosSession::STATUS_CLOSED ? $cashier->id : null,
            'opening_float_total' => $openingFloat,
            'expected_cash_total' => $openingFloat,
            'active_marker' => PosSession::activeMarkerForStatus($status),
        ]);

        if ($openingFloat > 0) {
            PosSessionCashEvent::create([
                'setting_id' => $setting->id,
                'pos_session_id' => $session->id,
                'event_type' => PosSessionCashEvent::EVENT_OPEN_FLOAT,
                'direction' => PosSessionCashEvent::DIRECTION_IN,
                'amount' => $openingFloat,
                'performed_by' => $cashier->id,
                'occurred_at' => now(),
            ]);
        }

        return $session;
    }
}
