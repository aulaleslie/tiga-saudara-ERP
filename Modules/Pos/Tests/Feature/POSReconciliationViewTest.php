<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSReconciliationViewTest extends TestCase
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
            'pos.reconciliation.access',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_reconciliation_page_loads_for_authorized_user(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, 'POS_ADMIN', ['pos.access', 'pos.reconciliation.access']);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.reconciliation.index'));

        $response->assertOk();
        $response->assertSee('Rekonsiliasi POS');
    }

    public function test_reconciliation_page_blocked_without_permission(): void
    {
        $setting = $this->createSetting('BIZ A');
        $cashier = $this->createUserForSetting($setting, 'POS_CASHIER', ['pos.access', 'pos.sell']);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.reconciliation.index'));

        $response->assertForbidden();
    }

    public function test_reconciliation_api_returns_session_data_perfect_match(): void
    {
        $setting = $this->createSetting('BIZ A');
        $admin = $this->createUserForSetting($setting, 'POS_ADMIN', ['pos.access', 'pos.reconciliation.access']);
        $cashier = $this->createUserForSetting($setting, 'CASHIER_1', ['pos.access', 'pos.sell']);
        
        $today = now()->format('Y-m-d');
        
        $terminal = PosTerminal::firstOrCreate(['setting_id' => $setting->id], [
            'code' => 'T1', 'name' => 'Terminal 1', 'is_active' => true
        ]);
        
        $session = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_CLOSED,
            'opening_float_total' => 100000,
            'expected_cash_total' => 350000,
            'counted_cash_total' => 350000,
            'variance_total' => 0,
            'opened_at' => Carbon::parse($today . ' 08:00:00'),
            'closed_at' => Carbon::parse($today . ' 17:00:00'),
        ]);

        PosSessionCashEvent::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'event_type' => PosSessionCashEvent::EVENT_OPEN_FLOAT,
            'direction' => PosSessionCashEvent::DIRECTION_IN,
            'amount' => 100000,
            'performed_by' => $cashier->id,
            'occurred_at' => Carbon::parse($today . ' 08:00:00'),
        ]);

        PosSessionCashEvent::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'event_type' => PosSessionCashEvent::EVENT_SAFE_DROP_OUT,
            'direction' => PosSessionCashEvent::DIRECTION_OUT,
            'amount' => 50000,
            'performed_by' => $cashier->id,
            'occurred_at' => Carbon::parse($today . ' 14:00:00'),
        ]);

        $customer = \Modules\People\Entities\Customer::create(['setting_id' => $setting->id, 'customer_name' => 'Test Cus', 'customer_email' => 'a@b.c', 'customer_phone' => '123']);

        // Sale 1: Cash 200,000
        $sale1 = Sale::forceCreate([
            'setting_id' => $setting->id, 'date' => $today, 'status' => Sale::STATUS_DISPATCHED,
            'customer_id' => $customer->id, 'customer_name' => $customer->customer_name,
            'total_amount' => 200000, 'paid_amount' => 200000, 'due_amount' => 0,
            'payment_status' => 'Paid', 'payment_method' => 'Cash',
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0
        ]);
        $payment1 = SalePayment::create([
            'sale_id' => $sale1->id, 'amount' => 200000, 'date' => $today, 'payment_method' => 'Cash', 'reference' => uniqid()
        ]);
        $this->createCheckout($setting->id, $session->id, $terminal->id, $cashier->id, $today . ' 10:00:00', 200000, 'cash', $sale1->id, $payment1->id);

        // Sale 2: Transfer 300,000
        $sale2 = Sale::forceCreate([
            'setting_id' => $setting->id, 'date' => $today, 'status' => Sale::STATUS_DISPATCHED,
            'customer_id' => $customer->id, 'customer_name' => $customer->customer_name,
            'total_amount' => 300000, 'paid_amount' => 300000, 'due_amount' => 0,
            'payment_status' => 'Paid', 'payment_method' => 'Transfer',
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0
        ]);
        $payment2 = SalePayment::create([
            'sale_id' => $sale2->id, 'amount' => 300000, 'date' => $today, 'payment_method' => 'Transfer', 'reference' => uniqid()
        ]);
        $this->createCheckout($setting->id, $session->id, $terminal->id, $cashier->id, $today . ' 11:00:00', 300000, 'transfer', $sale2->id, $payment2->id);

        // Sale 3: Cash 100,000
        $sale3 = Sale::forceCreate([
            'setting_id' => $setting->id, 'date' => $today, 'status' => Sale::STATUS_DISPATCHED,
            'customer_id' => $customer->id, 'customer_name' => $customer->customer_name,
            'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0,
            'payment_status' => 'Paid', 'payment_method' => 'Cash',
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0
        ]);
        $payment3 = SalePayment::create([
            'sale_id' => $sale3->id, 'amount' => 100000, 'date' => $today, 'payment_method' => 'Cash', 'reference' => uniqid()
        ]);
        $this->createCheckout($setting->id, $session->id, $terminal->id, $cashier->id, $today . ' 12:00:00', 100000, 'cash', $sale3->id, $payment3->id);

        $response = $this->actingAs($admin)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reconciliation.sessions', [
                'date_from' => $today,
                'date_to' => $today
            ]));

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'session_id' => $session->id,
                'cashier_name' => $cashier->name,
                'terminal_name' => $terminal->name,
                'opening_float' => 100000,
                'expected_cash' => 350000,
                'counted_cash' => 350000,
                'variance' => 0,
                'pos_checkout_total' => 600000,
                'pos_cash_sales_total' => 300000,
                'pos_non_cash_sales_total' => 300000,
                'posted_sales_total' => 600000,
                'posted_payments_total' => 600000,
                'safe_drop_total' => 50000,
                'has_mismatch' => false,
                'mismatch_details' => null,
            ]);
    }

    public function test_reconciliation_api_flags_mismatch_when_totals_differ(): void
    {
        $setting = $this->createSetting('BIZ A');
        $admin = $this->createUserForSetting($setting, 'POS_ADMIN', ['pos.access', 'pos.reconciliation.access']);
        $cashier = $this->createUserForSetting($setting, 'CASHIER_1', ['pos.access', 'pos.sell']);
        
        $today = now()->format('Y-m-d');
        
        $terminal = PosTerminal::firstOrCreate(['setting_id' => $setting->id], [
            'code' => 'T1', 'name' => 'Terminal 1', 'is_active' => true
        ]);
        
        $session = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_CLOSED,
            'opening_float_total' => 0,
            'expected_cash_total' => 200000,
            'counted_cash_total' => 200000,
            'variance_total' => 0,
            'opened_at' => Carbon::parse($today . ' 08:00:00'),
            'closed_at' => Carbon::parse($today . ' 17:00:00'),
        ]);

        $customer = \Modules\People\Entities\Customer::create(['setting_id' => $setting->id, 'customer_name' => 'Test Cus', 'customer_email' => 'a@b.c', 'customer_phone' => '123']);

        // Intentional mismatch: POS Checkout is 200,000 but Sale is 150,000 (corrupted)
        $sale = Sale::forceCreate([
            'setting_id' => $setting->id, 'date' => $today, 'status' => Sale::STATUS_DISPATCHED,
            'customer_id' => $customer->id, 'customer_name' => $customer->customer_name,
            'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0,
            'payment_status' => 'Paid', 'payment_method' => 'Cash',
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0
        ]);
        $payment = SalePayment::create([
            'sale_id' => $sale->id, 'amount' => 150000, 'date' => $today, 'payment_method' => 'Cash', 'reference' => uniqid()
        ]);
        
        $this->createCheckout($setting->id, $session->id, $terminal->id, $cashier->id, $today . ' 10:00:00', 200000, 'cash', $sale->id, $payment->id);

        $response = $this->actingAs($admin)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reconciliation.sessions', [
                'date_from' => $today,
                'date_to' => $today
            ]));

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'session_id' => $session->id,
                'pos_checkout_total' => 200000,
                'posted_sales_total' => 150000,
                'posted_payments_total' => 150000,
                'has_mismatch' => true,
            ]);
            
        $responseData = $response->json();
        $this->assertStringContainsString('POS totals do not match posted sales', $responseData[0]['mismatch_details']);
    }

    public function test_reconciliation_api_validates_date_params(): void
    {
        $setting = $this->createSetting('BIZ A');
        $admin = $this->createUserForSetting($setting, 'POS_ADMIN', ['pos.access', 'pos.reconciliation.access']);
        
        $response = $this->actingAs($admin)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reconciliation.sessions'));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_from', 'date_to']);
            
        $response = $this->actingAs($admin)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reconciliation.sessions', [
                'date_from' => '2026-02-28',
                'date_to' => '2026-02-27',
            ]));
            
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_reconciliation_scoped_to_setting(): void
    {
        $setting1 = $this->createSetting('BIZ A');
        $setting2 = $this->createSetting('BIZ B');
        $admin = $this->createUserForSetting($setting1, 'POS_ADMIN_1', ['pos.access', 'pos.reconciliation.access']);
        
        $today = now()->format('Y-m-d');
        
        $terminal1 = PosTerminal::firstOrCreate(['setting_id' => $setting1->id], [
            'code' => 'T1', 'name' => 'T1', 'is_active' => true
        ]);
        $cashier1 = $this->createUserForSetting($setting1, 'C1', ['pos.access']);
        $session1 = PosSession::create([
            'setting_id' => $setting1->id, 'terminal_id' => $terminal1->id, 'cashier_user_id' => $cashier1->id,
            'status' => PosSession::STATUS_CLOSED, 'opened_at' => now(), 'closed_at' => now()
        ]);
        
        $terminal2 = PosTerminal::firstOrCreate(['setting_id' => $setting2->id], [
            'code' => 'T2', 'name' => 'T2', 'is_active' => true
        ]);
        $cashier2 = $this->createUserForSetting($setting2, 'C2', ['pos.access']);
        $session2 = PosSession::create([
            'setting_id' => $setting2->id, 'terminal_id' => $terminal2->id, 'cashier_user_id' => $cashier2->id,
            'status' => PosSession::STATUS_CLOSED, 'opened_at' => now(), 'closed_at' => now()
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['setting_id' => $setting1->id])
            ->getJson(route('pos.reconciliation.sessions', [
                'date_from' => $today,
                'date_to' => $today
            ]));

        $response->assertOk()
            ->assertJsonCount(1);
            
        $this->assertEquals($session1->id, $response->json()[0]['session_id']);
    }

    // --- Helpers ---

    private function createCheckout(int $settingId, int $sessionId, int $terminalId, int $cashierId, string $date, float $amount, string $paymentMethod, int $saleId, int $salePaymentId): PosCheckout
    {
        // Get or create payment method based on code
        $methodName = match(strtolower($paymentMethod)) {
            'cash' => 'CASH',
            'transfer' => 'TRANSFER',
            'qris' => 'QRIS',
            default => strtoupper($paymentMethod),
        };

        $paymentMethodRecord = PaymentMethod::firstOrCreate(
            ['name' => $methodName],
            [
                'name' => $methodName,
                'is_cash' => strtolower($paymentMethod) === 'cash',
            ]
        );

        return PosCheckout::create([
            'setting_id' => $settingId,
            'pos_session_id' => $sessionId,
            'terminal_id' => $terminalId,
            'cashier_user_id' => $cashierId,
            'status' => 'POSTED',
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash',
            'subtotal' => $amount,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => $amount,
            'paid_total' => $amount,
            'change_total' => 0,
            'payment_method_id' => $paymentMethodRecord->id,
            'sale_id' => $saleId,
            'sale_payment_id' => $salePaymentId,
            'finalized_at' => Carbon::parse($date),
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

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => $roleName . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }
}
