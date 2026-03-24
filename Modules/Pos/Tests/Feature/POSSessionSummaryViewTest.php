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
