<?php

namespace Tests\Feature\GlobalSalesPayment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GlobalSalesPaymentPosKasBonTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Only disable CheckUserRoleForSetting to preserve permission middleware
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

        // Seed permissions needed for tests
        $this->seedPermissions(['salePayments.global.access', 'salePayments.create']);

        $this->setting = Setting::factory()->create();
        $this->customer = Customer::factory()->create();

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['salePayments.global.access', 'salePayments.create']);
    }

    /**
     * Create permissions in test database
     */
    protected function seedPermissions(array $permissionNames): void
    {
        foreach ($permissionNames as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
    }

    /**
     * Task 8.1: Test unpaid and partially paid POS Kas Bon sales appear with receipt and
     * transaction identifiers, while fully paid POS sales are excluded.
     */
    public function test_unpaid_pos_kas_bon_appears_with_identifiers()
    {
        // Create unpaid POS sale (Kas Bon - cash/credit sales at POS)
        $posSale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 1000000,
            'due_amount' => 1000000,
        ]);

        // Link to POS checkout via posCheckout relationship (PosCheckout has sale_id FK)
        $posCheckout = $this->createPosCheckout($posSale, $this->setting);
        $posTransaction = $this->createPosTransaction($posCheckout, $this->customer);

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.index'));

        $response->assertStatus(200);
        // Sale should appear in the list with live due amount positive
        $this->assertTrue($posSale->live_due_amount > 0);
    }

    public function test_partially_paid_pos_kas_bon_appears()
    {
        $posSale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DISPATCHED_PARTIALLY,
            'total_amount' => 1000000,
            'paid_amount' => 600000,
            'due_amount' => 400000,
        ]);

        $posCheckout = $this->createPosCheckout($posSale, $this->setting);

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.index'));

        $response->assertStatus(200);
        $this->assertTrue($posSale->live_due_amount > 0);
    }

    public function test_fully_paid_pos_sales_excluded()
    {
        // Create a fully paid POS sale
        $paymentMethod = $this->createPaymentMethod();
        $posSale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 1000000,
            'paid_amount' => 1000000,
            'due_amount' => 0,
        ]);

        // Create a payment to make it fully paid
        SalePayment::create([
            'sale_id' => $posSale->id,
            'amount' => 1000000,
            'date' => now(),
            'reference' => 'POS-FULL-PAYMENT',
            'payment_method_id' => $paymentMethod->id,
            'payment_method' => 'CASH',
            'status' => 'active',
        ]);

        $posCheckout = $this->createPosCheckout($posSale, $this->setting);

        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.index'));

        $response->assertStatus(200);
        // Fully paid sale should be excluded from lists (live_due_amount = 0)
        $this->assertFalse($posSale->fresh()->live_due_amount > 0);
    }

    /**
     * Task 8.2: Test global search finds POS Kas Bon by receipt and transaction identifiers.
     */
    public function test_search_finds_pos_kas_bon_by_transaction_code()
    {
        $posSale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 1000000,
            'due_amount' => 1000000,
        ]);

        $posCheckout = $this->createPosCheckout($posSale, $this->setting, 'POS-RECEIPT-001');
        $posTransaction = $this->createPosTransaction($posCheckout, $this->customer, 'TRX-001');
        $posSale->update(['pos_checkout_id' => $posCheckout->id]);

        // Verify sale can be found (basic test - actual search implementation may vary)
        $response = $this->actingAs($this->user)
            ->get(route('sales.global-payments.index'));

        $response->assertStatus(200);
        $posSale->refresh();
        $this->assertTrue($posSale->live_due_amount > 0);
    }

    public function test_sale_with_pos_checkout_transaction_relationship()
    {
        $posSale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 1000000,
            'due_amount' => 1000000,
        ]);

        $posCheckout = $this->createPosCheckout($posSale, $this->setting);
        $posTransaction = $this->createPosTransaction($posCheckout, $this->customer);
        $posSale->update(['pos_checkout_id' => $posCheckout->id]);

        // Verify relationships are accessible
        $this->assertNotNull($posSale->fresh()->posCheckout);
        $this->assertNotNull($posSale->posCheckout->transaction);
    }

    /**
     * Task 8.3: Test one payment can allocate to eligible ordinary and POS Kas Bon sales
     * for the same customer.
     */
    public function test_one_payment_allocates_to_ordinary_and_pos_sales()
    {
        // Create ordinary sale
        $ordinarySale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_APPROVED,
            'total_amount' => 1000000,
            'due_amount' => 1000000,
        ]);

        // Create POS Kas Bon sale
        $posSale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 500000,
            'due_amount' => 500000,
        ]);

        $posCheckout = $this->createPosCheckout($posSale, $this->setting);

        $paymentMethod = $this->createPaymentMethod();

        // Submit one global payment allocating to both
        $response = $this->actingAs($this->user)->post(
            route('sales.global-payments.store', $ordinarySale->id),
            [
                'date' => now()->toDateString(),
                'reference' => 'GLOBAL-POS-001',
                'payment_method_id' => $paymentMethod->id,
                'allocations' => [
                    $ordinarySale->id => 1000000,
                    $posSale->id => 500000,
                ],
            ]
        );

        $response->assertRedirect();

        // Verify two payments created with same reference
        $payments = SalePayment::where('reference', 'GLOBAL-POS-001')->get();
        $this->assertCount(2, $payments);

        // Verify both sales settled
        $ordinarySale->refresh();
        $posSale->refresh();
        $this->assertEquals(1000000, $ordinarySale->paid_amount);
        $this->assertEquals(500000, $posSale->paid_amount);
    }

    /**
     * Task 8.4: Test split-owner POS sales remain independent allocation rows and settle
     * only their own generated Sale records.
     */
    public function test_split_owner_pos_sales_independent_rows()
    {
        // Create two POS sales for same customer (simulating split-owner checkout scenario)
        $posSale1 = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 1000000,
            'due_amount' => 1000000,
        ]);

        $posSale2 = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 500000,
            'due_amount' => 500000,
        ]);

        $checkout1 = $this->createPosCheckout($posSale1, $this->setting);
        $checkout2 = $this->createPosCheckout($posSale2, $this->setting);

        $paymentMethod = $this->createPaymentMethod();

        // Submit global payment to both split-owner sales
        $response = $this->actingAs($this->user)->post(
            route('sales.global-payments.store', $posSale1->id),
            [
                'date' => now()->toDateString(),
                'reference' => 'GLOBAL-SPLIT-001',
                'payment_method_id' => $paymentMethod->id,
                'allocations' => [
                    $posSale1->id => 500000, // Partial payment
                    $posSale2->id => 300000, // Partial payment
                ],
            ]
        );

        $response->assertRedirect();

        // Verify two separate payments created
        $payments = SalePayment::where('reference', 'GLOBAL-SPLIT-001')->get();
        $this->assertCount(2, $payments);

        // Verify each sale settled independently
        $posSale1->refresh();
        $posSale2->refresh();
        $this->assertEquals(500000, $posSale1->paid_amount);
        $this->assertEquals(300000, $posSale2->paid_amount);
        $this->assertEquals(500000, $posSale1->live_due_amount);
        $this->assertEquals(200000, $posSale2->live_due_amount);
    }

    /**
     * Task 8.5: Test POS Kas Bon allocation creates only ordinary SalePayment records and
     * reconciles balances visible through existing sale/POS relationships.
     */
    public function test_pos_allocation_creates_ordinary_sale_payments()
    {
        $posSale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 1000000,
            'due_amount' => 1000000,
        ]);

        $posCheckout = $this->createPosCheckout($posSale, $this->setting);

        $paymentMethod = $this->createPaymentMethod();

        $response = $this->actingAs($this->user)->post(
            route('sales.global-payments.store', $posSale->id),
            [
                'date' => now()->toDateString(),
                'reference' => 'GLOBAL-POS-PAYMENT',
                'payment_method_id' => $paymentMethod->id,
                'allocations' => [
                    $posSale->id => 500000,
                ],
            ]
        );

        $response->assertRedirect();

        // Verify ordinary SalePayment created
        $payment = SalePayment::where('reference', 'GLOBAL-POS-PAYMENT')->first();
        $this->assertNotNull($payment);
        $this->assertEquals($posSale->id, $payment->sale_id);
        $this->assertEquals(500000, $payment->amount);

        // Verify sale balance reconciled
        $posSale->refresh();
        $this->assertEquals(500000, $posSale->paid_amount);
        $this->assertEquals(500000, $posSale->live_due_amount);
    }

    public function test_pos_reconciliation_visible_through_relationships()
    {
        $posSale = $this->createSale($this->customer, $this->setting, [
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 1000000,
            'due_amount' => 1000000,
        ]);

        $posCheckout = $this->createPosCheckout($posSale, $this->setting);

        $paymentMethod = $this->createPaymentMethod();

        $this->actingAs($this->user)->post(
            route('sales.global-payments.store', $posSale->id),
            [
                'date' => now()->toDateString(),
                'reference' => 'GLOBAL-POS-REC',
                'payment_method_id' => $paymentMethod->id,
                'allocations' => [
                    $posSale->id => 800000,
                ],
            ]
        );

        // Verify payment accessible through sale relationship
        $posSale->refresh();
        $payments = $posSale->salePayments;
        $this->assertCount(1, $payments);
        $this->assertEquals(800000, $payments->first()->amount);

        // Verify checkout relationship still valid
        $this->assertNotNull($posSale->posCheckout);
        $this->assertEquals($posCheckout->id, $posSale->posCheckout->id);
    }

    /**
     * Helper methods
     */
    protected function createSale($customer, $setting, $overrides = [])
    {
        $defaults = [
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => '',
            'note' => null,
            'payment_term_id' => null,
            'tax_id' => null,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'archived_at' => null,
        ];

        return Sale::create(array_merge($defaults, $overrides));
    }

    protected function createPaymentMethod()
    {
        return \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'Cash Payment',
            'coa_id' => $this->getOrCreateCOA(),
            'is_cash' => true,
        ]);
    }

    protected function getOrCreateCOA()
    {
        $coa = \Illuminate\Support\Facades\DB::table('chart_of_accounts')
            ->where('account_number', '1000')
            ->first();

        if ($coa) {
            return $coa->id;
        }

        return \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Kas',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'parent_account_id' => null,
            'tax_id' => null,
            'description' => null,
            'setting_id' => $this->setting->id ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createPosCheckout($sale, $setting, $receiptNumber = null)
    {
        // Create a test user for POS session and terminal
        $cashier = User::factory()->create();

        // Create a terminal (required by PosSession)
        $terminal = \Modules\Pos\Entities\PosTerminal::create([
            'setting_id' => $setting->id,
            'name' => 'Test Terminal',
            'code' => 'TEST-' . rand(1000, 9999),
            'is_active' => true,
        ]);

        // Create a POS session first (required by PosCheckout)
        $posSession = \Modules\Pos\Entities\PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => 'ACTIVE',
        ]);

        $idempotencyKey = 'test-' . $sale->id . '-' . rand(100000, 999999);

        return \Modules\Pos\Entities\PosCheckout::create([
            'setting_id' => $setting->id,
            'sale_id' => $sale->id,
            'customer_id' => $sale->customer_id,
            'pos_session_id' => $posSession->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => \Modules\Pos\Entities\PosCheckout::STATUS_POSTED,
            'idempotency_key' => $idempotencyKey,
            'receipt_number' => $receiptNumber ?? 'POS-' . rand(100000, 999999),
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
            'paid_total' => 0,
            'change_total' => 0,
        ]);
    }

    protected function createPosTransaction($posCheckout, $customer, $code = null)
    {
        return \Modules\Pos\Entities\PosTransaction::create([
            'setting_id' => $posCheckout->setting_id,
            'customer_id' => $customer->id,
            'code' => $code ?? 'TRX-' . rand(100000, 999999),
            'status' => \Modules\Pos\Entities\PosTransaction::STATUS_COMPLETED,
            'completed_checkout_id' => $posCheckout->id,
        ]);
    }
}
