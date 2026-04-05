<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSSessionSummaryViewTest extends TestCase
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
            'pos.sessions.view',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_summary_endpoint_returns_view_for_authorized_user(): void
    {
        $setting = $this->createSetting('BIZ SUMMARY VIEW A');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $session = $this->createOpenSession($setting, $cashier);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(200);
        $response->assertViewIs('pos::session.summary');
        $response->assertViewHas('session_id', $session->id);
    }

    public function test_summary_endpoint_returns_json_when_requested(): void
    {
        $setting = $this->createSetting('BIZ SUMMARY VIEW B');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $session = $this->createOpenSession($setting, $cashier);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('session_id', $session->id);
    }

    public function test_checkout_detail_endpoint_returns_correct_json(): void
    {
        $setting = $this->createSetting('BIZ SUMMARY VIEW C');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $terminal = $this->createTerminal($setting);
        $session = $this->createOpenSession($setting, $cashier, $terminal);
        
        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosCheckout::STATUS_POSTED,
            'receipt_number' => 'RCP-123',
            'grand_total' => 50000,
            'finalized_at' => now(),
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'payload_hash' => hash('sha256', 'test'),
            'payment_method_code' => 'CASH',
        ]);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.checkout-detail', [
                'session' => $session->id,
                'checkout' => $checkout->id
            ]));

        $response->assertStatus(200);
        $response->assertJsonPath('id', $checkout->id);
        $response->assertJsonPath('receipt_number', 'RCP-123');
    }

    public function test_summary_json_contains_aggregated_payment_methods(): void
    {
        $setting = $this->createSetting('BIZ SUMMARY VIEW E');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $terminal = $this->createTerminal($setting);
        $session = $this->createOpenSession($setting, $cashier, $terminal);

        $coaId = \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Test COA',
            'account_number' => 'ACC-TEST',
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $methodCash = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'CASH',
            'is_cash' => true,
            'coa_id' => $coaId
        ]);
        $methodQRIS = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'QRIS',
            'is_cash' => false,
            'coa_id' => $coaId,
            'requires_reference' => true
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosCheckout::STATUS_POSTED,
            'receipt_number' => 'RCP-MULTI',
            'grand_total' => 100000,
            'finalized_at' => now(),
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'payload_hash' => hash('sha256', 'multi-payment-test'),
            'payment_method_id' => $methodCash->id,
        ]);

        \Modules\Pos\Entities\PosCheckoutPayment::create([
            'pos_checkout_id' => $checkout->id,
            'payment_method_id' => $methodCash->id,
            'amount_minor_units' => 6000000,
            'sequence_order' => 1,
        ]);

        \Modules\Pos\Entities\PosCheckoutPayment::create([
            'pos_checkout_id' => $checkout->id,
            'payment_method_id' => $methodQRIS->id,
            'amount_minor_units' => 4000000,
            'sequence_order' => 2,
        ]);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(200);
        $transactions = $response->json('transactions');
        $this->assertNotEmpty($transactions);
        $this->assertEquals('CASH, QRIS', $transactions[0]['payment_method']);
    }

    public function test_unauthorized_user_cannot_access_summary(): void
    {
        $setting = $this->createSetting('BIZ SUMMARY VIEW D');
        $cashierA = $this->createUserForSetting($setting, ['pos.access']);
        $cashierB = $this->createUserForSetting($setting, ['pos.access']);
        $sessionA = $this->createOpenSession($setting, $cashierA);

        // Cashier B tries to access Cashier A's session summary
        $response = $this->actingAs($cashierB)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.summary', ['session' => $sessionA->id]));

        $response->assertStatus(403);
    }

    public function test_summary_endpoint_returns_json_422_on_domain_exception(): void
    {
        $setting = $this->createSetting('BIZ SUMMARY VIEW F');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $session = $this->createOpenSession($setting, $cashier);

        $mockService = $this->createMock(\Modules\Pos\Services\PosSessionSummaryService::class);
        $mockService->method('getSummary')->willThrowException(new \DomainException('Session not found in summary service'));
        $this->app->instance(\Modules\Pos\Services\PosSessionSummaryService::class, $mockService);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Session not found in summary service',
        ]);
    }

    public function test_summary_endpoint_returns_json_500_on_unexpected_exception(): void
    {
        $setting = $this->createSetting('BIZ SUMMARY VIEW G');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $session = $this->createOpenSession($setting, $cashier);

        $mockService = $this->createMock(\Modules\Pos\Services\PosSessionSummaryService::class);
        $mockService->method('getSummary')->willThrowException(new \Exception('Database connection error'));
        $this->app->instance(\Modules\Pos\Services\PosSessionSummaryService::class, $mockService);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(500);
        $response->assertJson([
            'message' => 'Internal server error',
        ]);
    }

    public function test_summary_endpoint_success_still_returns_json_for_ajax(): void
    {
        $setting = $this->createSetting('BIZ SUMMARY VIEW H');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $session = $this->createOpenSession($setting, $cashier);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('session_id', $session->id);
    }

    public function test_summary_endpoint_success_still_renders_view_for_html(): void
    {
        $setting = $this->createSetting('BIZ SUMMARY VIEW I');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $session = $this->createOpenSession($setting, $cashier);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(200);
        $response->assertViewIs('pos::session.summary');
        $response->assertViewHas('session_id', $session->id);
    }

    public function test_non_terminal_session_summary_returns_transactions(): void
    {
        $setting = $this->createSetting('BIZ NON-TERMINAL TEST A');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $session = $this->createOpenSession($setting, $cashier, null); // Non-terminal session

        // Create some PosTransaction records
        $transaction1 = \Modules\Pos\Entities\PosTransaction::create([
            'setting_id' => $setting->id,
            'source_pos_session_id' => $session->id,
            'code' => 'TRX-001',
            'owner_user_id' => $cashier->id,
            'created_by' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'snapshot_totals' => ['grand_total' => 50000],
            'status' => 'DRAFT',
            'created_at' => now(),
        ]);

        $transaction2 = \Modules\Pos\Entities\PosTransaction::create([
            'setting_id' => $setting->id,
            'source_pos_session_id' => $session->id,
            'code' => 'TRX-002',
            'owner_user_id' => $cashier->id,
            'created_by' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'snapshot_totals' => ['grand_total' => 75000],
            'status' => 'DRAFT',
            'created_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('session_id', $session->id);
        $response->assertJsonPath('terminal_id', null);
        $response->assertJsonPath('cashier_name', $cashier->name);

        // Verify transactions are in response (non-terminal uses PosTransaction)
        $transactions = $response->json('transactions');
        $this->assertCount(2, $transactions);
        $this->assertEquals('TRX-001', $transactions[0]['code']);
        $this->assertEquals('TRX-002', $transactions[1]['code']);

        // Verify cash_events are present but we're focused on transactions
        $this->assertArrayHasKey('cash_events', $response->json());
    }

    public function test_terminal_session_summary_returns_checkouts(): void
    {
        $setting = $this->createSetting('BIZ TERMINAL TEST A');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $terminal = $this->createTerminal($setting);
        $session = $this->createOpenSession($setting, $cashier, $terminal); // Terminal session

        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosCheckout::STATUS_POSTED,
            'receipt_number' => 'RCP-T001',
            'grand_total' => 100000,
            'finalized_at' => now(),
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'payload_hash' => hash('sha256', 'test'),
            'payment_method_code' => 'CASH',
        ]);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('session_id', $session->id);
        $response->assertJsonPath('terminal_id', $terminal->id);
        $response->assertJsonPath('cashier_name', $cashier->name);

        // Verify checkouts (transactions) are in response for terminal sessions
        $transactions = $response->json('transactions');
        $this->assertCount(1, $transactions);
        $this->assertEquals('RCP-T001', $transactions[0]['receipt_number']);

        // Verify cash_events are present
        $this->assertArrayHasKey('cash_events', $response->json());
    }

    public function test_non_terminal_session_view_includes_cashier_name(): void
    {
        $setting = $this->createSetting('BIZ NON-TERMINAL TEST B');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $session = $this->createOpenSession($setting, $cashier, null); // Non-terminal session

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(200);
        $response->assertViewHas('cashier_name', $cashier->name);
    }

    public function test_terminal_session_view_includes_cashier_name(): void
    {
        $setting = $this->createSetting('BIZ TERMINAL TEST B');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $terminal = $this->createTerminal($setting);
        $session = $this->createOpenSession($setting, $cashier, $terminal); // Terminal session

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(200);
        $response->assertViewHas('cashier_name', $cashier->name);
    }

    public function test_non_terminal_session_empty_transactions_shows_empty_state(): void
    {
        $setting = $this->createSetting('BIZ NON-TERMINAL TEST C');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $session = $this->createOpenSession($setting, $cashier, null); // Non-terminal session with no transactions

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('total_transactions_count', 0);
        $response->assertJsonPath('total_transactions_amount', 0);

        $transactions = $response->json('transactions');
        $this->assertCount(0, $transactions);
    }

    public function test_service_loads_cashier_relationship_for_all_sessions(): void
    {
        $setting = $this->createSetting('BIZ CASHIER RELATIONSHIP TEST');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $session = $this->createOpenSession($setting, $cashier);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('cashier_user_id', $cashier->id);
        $response->assertJsonPath('cashier_name', $cashier->name);
        $this->assertNotNull($response->json('cashier_name'));
    }

    public function test_transaction_limit_of_50_applied_for_non_terminal(): void
    {
        $setting = $this->createSetting('BIZ NON-TERMINAL LIMIT TEST');
        $cashier = $this->createUserForSetting($setting, ['pos.access']);
        $session = $this->createOpenSession($setting, $cashier, null);

        // Create 60 transactions
        for ($i = 1; $i <= 60; $i++) {
            \Modules\Pos\Entities\PosTransaction::create([
                'setting_id' => $setting->id,
                'source_pos_session_id' => $session->id,
                'code' => 'TRX-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'owner_user_id' => $cashier->id,
                'created_by' => $cashier->id,
                'last_saved_by' => $cashier->id,
                'snapshot_totals' => ['grand_total' => 10000],
                'status' => 'DRAFT',
                'created_at' => now()->subMinutes(61 - $i),
            ]);
        }

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sessions.summary', ['session' => $session->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('total_transactions_count', 60); // Total count

        // But only 50 should be in transactions array
        $transactions = $response->json('transactions');
        $this->assertCount(50, $transactions);
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
        $roleName = 'Role_' . uniqid();
        $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminal(Setting $setting): PosTerminal
    {
        return PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'T-' . uniqid(),
            'name' => 'Test Terminal',
            'is_active' => true,
        ]);
    }

    private function createOpenSession(Setting $setting, User $cashier, ?PosTerminal $terminal = null): PosSession
    {
        return PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal?->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
        ]);
    }
}
