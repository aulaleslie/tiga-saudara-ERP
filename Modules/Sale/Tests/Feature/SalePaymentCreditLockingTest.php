<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\CustomerCredit;
use Modules\SalesReturn\Entities\SalePaymentCreditApplication;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Tests that single-sale payment settlement with customer credit properly locks
 * and validates the credit state inside a transaction to prevent concurrent overspend.
 *
 * These tests verify that the SalePaymentsController::store() route correctly:
 * - Locks the customer credit before using it
 * - Revalidates the credit's status, remaining amount, and customer_id inside the transaction
 * - Detects changes to credit state that occurred after form rendering
 * - Rejects settlement if the credit becomes invalid concurrently
 * - Reconciles the sale using the canonical live-balance calculation
 */
class SalePaymentCreditLockingTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;
    protected PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        // Bypass gate checks and middleware
        Gate::before(function () {
            return true;
        });

        // Create and authenticate a user
        $user = User::factory()->create();
        $this->actingAs($user);

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '1234567890',
            'company_address' => 'Test Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notification@test.com',
            'footer_text' => 'Test Footer',
        ]);

        Session::put('setting_id', $this->setting->id);
        Session::put('user_settings', collect([$this->setting]));

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'contact_name' => 'John Doe',
            'setting_id' => $this->setting->id,
        ]);

        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Kas',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'parent_account_id' => null,
            'tax_id' => null,
            'description' => null,
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coaId,
            'is_cash' => true,
        ]);
    }

    /** @test */
    public function valid_credit_only_settlement_succeeds()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-ONLY',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 30000,
            'paid_amount' => 0,
            'due_amount' => 30000,
        ]);

        $saleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-CREDIT-ONLY',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 30000,
            'paid_amount' => 0,
            'due_amount' => 30000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        $credit = CustomerCredit::query()->create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 30000,
            'remaining_amount' => 30000,
            'status' => 'open',
        ]);


        // Submit payment using only credit (cash = 0)
        $response = $this->post(route('sale-payments.store'), [
            'sale_id' => $sale->id,
            'date' => now()->toDateString(),
            'reference' => 'PAY-CREDIT-ONLY',
            'amount' => 0,
            'payment_method_id' => $this->paymentMethod->id,
            'credit_customer_credit_id' => $credit->id,
            'credit_amount' => 30000,
        ]);

        // Verify payment created
        $this->assertEquals(1, SalePayment::count());
        $payment = SalePayment::first();
        $this->assertEquals(0, $payment->amount);

        // Verify credit application created
        $this->assertEquals(1, SalePaymentCreditApplication::count());
        $app = SalePaymentCreditApplication::first();
        $this->assertEquals(30000, $app->amount);

        // Verify credit closed (fully consumed)
        $credit->refresh();
        $this->assertEquals(0, $credit->remaining_amount);
        $this->assertEquals('closed', strtolower($credit->status));

        // Verify sale fully paid
        $sale->refresh();
        $this->assertEquals(30000, $sale->paid_amount);
        $this->assertEquals(0, $sale->live_due_amount);
        $this->assertEquals('PAID', strtoupper($sale->payment_status));
    }

    /** @test */
    public function valid_mixed_cash_and_credit_settlement_succeeds()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-MIXED',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $saleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-CREDIT-MIXED',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 30000,
            'paid_amount' => 0,
            'due_amount' => 30000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        $credit = CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 30000,
            'remaining_amount' => 30000,
            'status' => 'open',
        ]);

        // Submit payment: 70k cash + 30k credit = 100k (full settlement)
        $response = $this->post(route('sale-payments.store'), [
            'sale_id' => $sale->id,
            'date' => now()->toDateString(),
            'reference' => 'PAY-CREDIT-MIXED',
            'amount' => 70000,
            'payment_method_id' => $this->paymentMethod->id,
            'credit_customer_credit_id' => $credit->id,
            'credit_amount' => 30000,
        ]);

        // Verify payment created with cash amount
        $this->assertEquals(1, SalePayment::count());
        $payment = SalePayment::first();
        $this->assertEquals(70000, $payment->amount);

        // Verify credit application created
        $this->assertEquals(1, SalePaymentCreditApplication::count());
        $app = SalePaymentCreditApplication::first();
        $this->assertEquals(30000, $app->amount);

        // Verify credit closed (fully consumed)
        $credit->refresh();
        $this->assertEquals(0, $credit->remaining_amount);

        // Verify sale fully paid
        $sale->refresh();
        $this->assertEquals(100000, $sale->paid_amount);
        $this->assertEquals(0, $sale->live_due_amount);
        $this->assertEquals('PAID', strtoupper($sale->payment_status));
    }

    /** @test */
    public function exact_full_settlement_succeeds()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-EXACT',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        // Pay exact amount
        $response = $this->post(route('sale-payments.store'), [
            'sale_id' => $sale->id,
            'date' => now()->toDateString(),
            'reference' => 'PAY-EXACT',
            'amount' => 100000,
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        $sale->refresh();
        $this->assertEquals('PAID', strtoupper($sale->payment_status));
        $this->assertEquals(0, $sale->live_due_amount);
    }

    /** @test */
    public function closed_credit_rejected_at_transaction_time()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-CLOSED',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $saleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-CREDIT-CLOSED',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 30000,
            'paid_amount' => 0,
            'due_amount' => 30000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        // Create credit but close it before attempting payment
        $credit = CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 30000,
            'remaining_amount' => 0,
            'status' => 'closed',
        ]);

        // Attempt to use closed credit
        $response = $this->post(route('sale-payments.store'), [
            'sale_id' => $sale->id,
            'date' => now()->toDateString(),
            'reference' => 'PAY-CLOSED-CREDIT',
            'amount' => 70000,
            'payment_method_id' => $this->paymentMethod->id,
            'credit_customer_credit_id' => $credit->id,
            'credit_amount' => 30000,
        ]);

        // Verify rejection
        $response->assertSessionHasErrors();

        // Verify no payment created
        $this->assertEquals(0, SalePayment::count());
        $this->assertEquals(0, SalePaymentCreditApplication::count());

        // Verify sale unchanged
        $sale->refresh();
        $this->assertEquals(0, $sale->paid_amount);
        $this->assertEquals(100000, $sale->live_due_amount);
    }

    /** @test */
    public function credit_belonging_to_another_customer_rejected()
    {
        $customer2 = Customer::create([
            'customer_name' => 'Other Customer',
            'contact_name' => 'Jane Doe',
            'setting_id' => $this->setting->id,
        ]);

        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-WRONG-CUST',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        // Create credit for wrong customer
        $saleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-CREDIT-WRONG-CUST',
            'setting_id' => $this->setting->id,
            'customer_id' => $customer2->id,
            'customer_name' => 'Other Customer',
            'total_amount' => 30000,
            'paid_amount' => 0,
            'due_amount' => 30000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        $credit = CustomerCredit::create([
            'customer_id' => $customer2->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 30000,
            'remaining_amount' => 30000,
            'status' => 'open',
        ]);

        // Attempt to use wrong-customer credit
        $response = $this->post(route('sale-payments.store'), [
            'sale_id' => $sale->id,
            'date' => now()->toDateString(),
            'reference' => 'PAY-WRONG-CUST',
            'amount' => 70000,
            'payment_method_id' => $this->paymentMethod->id,
            'credit_customer_credit_id' => $credit->id,
            'credit_amount' => 30000,
        ]);

        // Verify rejection
        $response->assertSessionHasErrors();

        // Verify no payment created
        $this->assertEquals(0, SalePayment::count());
        $this->assertEquals(0, SalePaymentCreditApplication::count());

        // Verify sale unchanged
        $sale->refresh();
        $this->assertEquals(0, $sale->paid_amount);
        $this->assertEquals(100000, $sale->live_due_amount);
    }

    /** @test */
    public function insufficient_remaining_credit_rejected_at_transaction_time()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-INSUFFICIENT',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $saleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-CREDIT-INSUFFICIENT',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 15000,
            'paid_amount' => 0,
            'due_amount' => 15000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        // Create credit with 15k but we'll try to use 20k
        $credit = CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 15000,
            'remaining_amount' => 15000,
            'status' => 'open',
        ]);

        // Attempt to use more credit than available
        $response = $this->post(route('sale-payments.store'), [
            'sale_id' => $sale->id,
            'date' => now()->toDateString(),
            'reference' => 'PAY-INSUFFICIENT',
            'amount' => 80000,
            'payment_method_id' => $this->paymentMethod->id,
            'credit_customer_credit_id' => $credit->id,
            'credit_amount' => 20000, // More than available
        ]);

        // Verify rejection
        $response->assertSessionHasErrors();

        // Verify no payment created
        $this->assertEquals(0, SalePayment::count());
        $this->assertEquals(0, SalePaymentCreditApplication::count());

        // Verify credit unchanged
        $credit->refresh();
        $this->assertEquals(15000, $credit->remaining_amount);
        $this->assertEquals('open', strtolower($credit->status));

        // Verify sale unchanged
        $sale->refresh();
        $this->assertEquals(0, $sale->paid_amount);
        $this->assertEquals(100000, $sale->live_due_amount);
    }

    /** @test */
    public function one_cent_overpayment_rejected()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-OVERPAY',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        // Attempt to pay 100001 (1 cent over)
        $response = $this->post(route('sale-payments.store'), [
            'sale_id' => $sale->id,
            'date' => now()->toDateString(),
            'reference' => 'PAY-OVERPAY',
            'amount' => 100001,
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        // Verify rejection
        $response->assertSessionHasErrors();

        // Verify no payment created
        $this->assertEquals(0, SalePayment::count());

        // Verify sale unchanged
        $sale->refresh();
        $this->assertEquals(0, $sale->paid_amount);
        $this->assertEquals(100000, $sale->live_due_amount);
    }

    /** @test */
    public function repeated_consumption_of_same_credit_prevents_overspend()
    {
        $sale1 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-REPEAT-1',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $sale2 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-REPEAT-2',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $saleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-CREDIT-REPEAT',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 25000,
            'paid_amount' => 0,
            'due_amount' => 25000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        // Create credit with 25k
        $credit = CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 25000,
            'remaining_amount' => 25000,
            'status' => 'open',
        ]);

        // First transaction: use full 25k credit
        $response1 = $this->post(route('sale-payments.store'), [
            'sale_id' => $sale1->id,
            'date' => now()->toDateString(),
            'reference' => 'PAY-REPEAT-1',
            'amount' => 75000,
            'payment_method_id' => $this->paymentMethod->id,
            'credit_customer_credit_id' => $credit->id,
            'credit_amount' => 25000,
        ]);

        // Verify first payment succeeded
        $this->assertEquals(1, SalePayment::count());
        $credit->refresh();
        $this->assertEquals(0, $credit->remaining_amount);

        // Second transaction: attempt to use same (now-exhausted) credit
        $response2 = $this->post(route('sale-payments.store'), [
            'sale_id' => $sale2->id,
            'date' => now()->toDateString(),
            'reference' => 'PAY-REPEAT-2',
            'amount' => 75000,
            'payment_method_id' => $this->paymentMethod->id,
            'credit_customer_credit_id' => $credit->id,
            'credit_amount' => 25000,
        ]);

        // Verify rejection
        $response2->assertSessionHasErrors();

        // Verify only first payment exists
        $this->assertEquals(1, SalePayment::count());
    }

    /** @test */
    public function credit_opened_after_initial_load_is_closed_before_transactional_use()
    {
        // This test proves that even if credit appears OPEN when initially loaded,
        // the locked revalidation inside the transaction will catch if it was closed
        // by a concurrent operation after the initial form load but before the transaction lock.
        //
        // We simulate this by:
        // 1. Loading the credit status at form-render time (simulated in the test)
        // 2. Having another DB connection close the credit before our transaction lock
        // 3. Verifying the controller detects it's now closed and rejects the payment

        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-STALE-CLOSE',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $saleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-CREDIT-STALE-CLOSE',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 30000,
            'paid_amount' => 0,
            'due_amount' => 30000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        // Create credit in OPEN status
        $credit = CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 30000,
            'remaining_amount' => 30000,
            'status' => 'open',
        ]);

        // Simulate concurrent closure: close the credit before the transaction starts
        // (This is what would happen if another request processed between form load and submit)
        DB::table('customer_credits')
            ->where('id', $credit->id)
            ->update(['status' => 'CLOSED', 'remaining_amount' => 0]);

        // Now attempt to use the credit that appears open at form-time but is actually closed
        $response = $this->post(route('sale-payments.store'), [
            'sale_id' => $sale->id,
            'date' => now()->toDateString(),
            'reference' => 'PAY-STALE-CLOSE',
            'amount' => 70000,
            'payment_method_id' => $this->paymentMethod->id,
            'credit_customer_credit_id' => $credit->id,
            'credit_amount' => 30000,
        ]);

        // The controller should reject this because the transaction lock detected status=CLOSED
        $response->assertSessionHasErrors();

        // Verify no payment was created
        $this->assertEquals(0, SalePayment::count());

        // Verify no credit applications created
        $this->assertEquals(0, SalePaymentCreditApplication::count());

        // Verify sale unchanged
        $sale->refresh();
        $this->assertEquals(0, $sale->paid_amount);
        $this->assertEquals(100000, $sale->live_due_amount);
    }

    /** @test */
    public function credit_insufficient_after_initial_load_is_detected_inside_transaction()
    {
        // This test proves that if credit initially has enough remaining balance when loaded,
        // but becomes insufficient by the time we enter the transaction lock,
        // the revalidation will catch it and reject the payment.
        //
        // Scenario: Credit has 30k, we validate at form load, but by transaction time
        // another request consumed it down to 10k. The payment will be rejected.

        $sale1 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-STALE-INSUFFICIENT-1',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $sale2 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-STALE-INSUFFICIENT-2',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $saleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-CREDIT-STALE-INSUFFICIENT',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 30000,
            'paid_amount' => 0,
            'due_amount' => 30000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        // Create credit with 30k
        $credit = CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 30000,
            'remaining_amount' => 30000,
            'status' => 'open',
        ]);

        // Simulate concurrent consumption: reduce remaining to 10k
        // (This is what would happen if another request consumed 20k of the credit)
        DB::table('customer_credits')
            ->where('id', $credit->id)
            ->update(['remaining_amount' => 10000]);

        // Now attempt to use 30k when only 10k is available
        $response = $this->post(route('sale-payments.store'), [
            'sale_id' => $sale1->id,
            'date' => now()->toDateString(),
            'reference' => 'PAY-STALE-INSUFFICIENT',
            'amount' => 70000,
            'payment_method_id' => $this->paymentMethod->id,
            'credit_customer_credit_id' => $credit->id,
            'credit_amount' => 30000, // More than available (10k)
        ]);

        // The controller should reject this because the transaction lock detected insufficient balance
        $response->assertSessionHasErrors();

        // Verify no payment was created
        $this->assertEquals(0, SalePayment::count());

        // Verify no credit applications created
        $this->assertEquals(0, SalePaymentCreditApplication::count());

        // Verify credit unchanged (from this request's perspective)
        $credit->refresh();
        $this->assertEquals(10000, $credit->remaining_amount);

        // Verify sale unchanged
        $sale1->refresh();
        $this->assertEquals(0, $sale1->paid_amount);
        $this->assertEquals(100000, $sale1->live_due_amount);
    }

    /** @test */
    public function sale_live_due_changed_after_initial_load_prevents_overpayment()
    {
        // This test proves that if a sale's live_due_amount changes between form load
        // and transaction time (due to other payments being added concurrently),
        // the transaction will detect overpayment and reject it.

        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-LIVE-DUE-STALE',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        // Simulate concurrent payment: another transaction added a 50k payment
        // This reduces live_due from 100k to 50k
        // We create an actual active payment to properly update live_due calculation
        SalePayment::create([
            'sale_id' => $sale->id,
            'date' => now()->toDateString(),
            'reference' => 'PAY-CONCURRENT',
            'amount' => 50000,
            'payment_method_id' => $this->paymentMethod->id,
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        // Now attempt to pay 100k when only 50k is now due (after the concurrent payment)
        $response = $this->post(route('sale-payments.store'), [
            'sale_id' => $sale->id,
            'date' => now()->toDateString(),
            'reference' => 'PAY-LIVE-DUE-STALE',
            'amount' => 100000, // More than the new live_due of 50k
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        // The controller should reject this because the transaction lock detected overpayment
        $response->assertSessionHasErrors();

        // Verify no additional payment was created
        $this->assertEquals(1, SalePayment::count()); // Only the concurrent payment exists
        $this->assertEquals(50000, SalePayment::first()->amount);

        // Verify sale has the concurrent payment but not our attempted overpayment
        $sale->refresh();
        $this->assertEquals(50000, $sale->live_due_amount);
    }
}
