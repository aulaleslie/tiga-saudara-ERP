<?php

namespace Modules\Sale\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleLiveBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'contact_name' => 'John Doe',
            'setting_id' => $this->setting->id,
        ]);
    }

    /** @test */
    public function it_calculates_live_due_amount_from_active_payments()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-001',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        // No payments yet
        $this->assertEquals(1000000, $sale->live_due_amount);

        // Add an active payment
        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 300000,
            'date' => now(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $sale->refresh();
        $this->assertEquals(700000, $sale->live_due_amount);
    }

    /** @test */
    public function it_excludes_invalidated_payments_from_live_due()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-002',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        // Add active and invalidated payments
        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 400000,
            'date' => now(),
            'reference' => 'PAY-ACTIVE-001',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 200000,
            'date' => now(),
            'reference' => 'PAY-INVALID-001',
            'payment_method' => 'Transfer',
            'status' => SalePayment::STATUS_INVALIDATED,
        ]);

        $sale->refresh();
        // Only active payment counts
        $this->assertEquals(600000, $sale->live_due_amount);
    }

    /** @test */
    public function it_handles_decimal_rounding_correctly()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-003',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 999999.99,
            'paid_amount' => 0,
            'due_amount' => 999999.99,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 333333.33,
            'date' => now(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 333333.33,
            'date' => now(),
            'reference' => 'PAY-002',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $sale->refresh();
        $expectedDue = round(999999.99 - 333333.33 - 333333.33, 2);
        $this->assertEqualsWithDelta($expectedDue, $sale->live_due_amount, 0.01);
    }

    /** @test */
    public function it_returns_zero_when_sale_is_fully_paid()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-004',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 500000,
            'paid_amount' => 0,
            'due_amount' => 500000,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 500000,
            'date' => now(),
            'reference' => 'PAY-FULL',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $sale->refresh();
        $this->assertEquals(0, $sale->live_due_amount);
    }

    /** @test */
    public function it_returns_zero_when_over_paid_is_attempted()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-005',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 500000,
            'paid_amount' => 0,
            'due_amount' => 500000,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 600000,
            'date' => now(),
            'reference' => 'PAY-OVER',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $sale->refresh();
        // live_due_amount never goes negative
        $this->assertEquals(0, $sale->live_due_amount);
    }

    /** @test */
    public function scope_filters_sales_with_live_due_greater_than_zero()
    {
        $saleWithDue = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-WITH-DUE',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 500000,
            'paid_amount' => 0,
            'due_amount' => 500000,
        ]);

        $saleFullyPaid = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-PAID',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'due_amount' => 0,
        ]);

        SalePayment::create([
            'sale_id' => $saleFullyPaid->id,
            'amount' => 500000,
            'date' => now(),
            'reference' => 'PAY-FULL',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $results = Sale::whereLiveDueAmountGreaterThan(0)->get();
        $this->assertTrue($results->contains($saleWithDue));
        $this->assertFalse($results->contains($saleFullyPaid));
    }

    /** @test */
    public function scope_filters_approved_up_sales()
    {
        $approved = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-APPROVED',
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

        $dispatchedPartially = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-PARTIAL',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_DISPATCHED_PARTIALLY,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $dispatched = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-DISPATCHED',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $drafted = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-DRAFTED',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        $results = Sale::approvedUp()->get();
        $this->assertTrue($results->contains($approved));
        $this->assertTrue($results->contains($dispatchedPartially));
        $this->assertTrue($results->contains($dispatched));
        $this->assertFalse($results->contains($drafted));
    }

    /** @test */
    public function get_effective_paid_amount_returns_sum_of_active_payments()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-006',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 250000,
            'date' => now(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 150000,
            'date' => now(),
            'reference' => 'PAY-002',
            'payment_method' => 'Transfer',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 100000,
            'date' => now(),
            'reference' => 'PAY-INVALID',
            'payment_method' => 'Check',
            'status' => SalePayment::STATUS_INVALIDATED,
        ]);

        $this->assertEquals(400000, $sale->getEffectivePaidAmount());
    }

    /** @test */
    public function it_includes_credit_applications_in_paid_amount()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-001',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 600000,
            'date' => now(),
            'reference' => 'PAY-CASH',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        // Create a sale return and credit
        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-001',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 300000,
            'paid_amount' => 0,
            'due_amount' => 300000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 500000,
            'remaining_amount' => 300000,
            'status' => 'open',
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 300000,
        ]);

        // Effective paid = 600000 + 300000 = 900000
        $this->assertEquals(900000, $sale->getEffectivePaidAmount());
    }

    /** @test */
    public function it_excludes_credit_applications_on_invalidated_payments()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-INVALID',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        // Create sale return and credit for use
        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-INVALID',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 500000,
            'paid_amount' => 0,
            'due_amount' => 500000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 500000,
            'remaining_amount' => 250000,
            'status' => 'open',
        ]);

        // Active payment with credit
        $activePayment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 300000,
            'date' => now(),
            'reference' => 'PAY-ACTIVE-CREDIT',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $activePayment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 150000,
        ]);

        // Invalidated payment with credit (should be excluded)
        $invalidPayment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 200000,
            'date' => now(),
            'reference' => 'PAY-INVALID-CREDIT',
            'payment_method' => 'Transfer',
            'status' => SalePayment::STATUS_INVALIDATED,
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $invalidPayment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 100000,
        ]);

        // Effective paid = 300000 + 150000 = 450000 (invalidated payment and its credit excluded)
        $this->assertEquals(450000, $sale->getEffectivePaidAmount());
    }

    /** @test */
    public function it_reconciles_sale_with_existing_credit()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-RECONCILE-CREDIT',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        // Create sale return and credit
        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-RECONCILE',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 500000,
            'paid_amount' => 0,
            'due_amount' => 500000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        // Pre-existing credit application
        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 500000,
            'date' => now(),
            'reference' => 'PAY-EXISTING-CREDIT',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 500000,
            'remaining_amount' => 300000,
            'status' => 'open',
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 300000,
        ]);

        $sale->reconcileFromActivePayments();
        $sale->refresh();

        // paid_amount = 500000 + 300000 = 800000
        // due_amount = 1000000 - 800000 = 200000
        $this->assertEquals(800000, $sale->paid_amount);
        $this->assertEquals(200000, $sale->due_amount);
        $this->assertEquals('PARTIAL', $sale->payment_status);
    }

    /** @test */
    public function live_due_amount_is_fully_settled_with_credit()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-FULLY-SETTLED-CREDIT',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        // Create sale return and credit
        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-SETTLE',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 500000,
            'paid_amount' => 0,
            'due_amount' => 500000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        // Payment + credit = total
        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 700000,
            'date' => now(),
            'reference' => 'PAY-PARTIAL-SETTLEMENT',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 500000,
            'remaining_amount' => 300000,
            'status' => 'open',
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 300000,
        ]);

        $sale->refresh();
        $this->assertEquals(0, $sale->live_due_amount);
    }

    // Phase 1 Repair: Explicit SQL scope tests

    /** @test */
    public function scope_whereLiveDueAmountGreaterThan_excludes_fully_cash_settled_sale()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CASH-SETTLED',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'due_amount' => 0,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 500000,
            'date' => now(),
            'reference' => 'PAY-CASH-FULL',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $results = Sale::whereLiveDueAmountGreaterThan(0)->get();
        $this->assertFalse($results->contains($sale), 'Fully cash-settled sale should not be in positive live due scope');
    }

    /** @test */
    public function scope_whereLiveDueAmountLessThanOrEqual_includes_fully_cash_settled_sale()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CASH-SETTLED-LE',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'due_amount' => 0,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 500000,
            'date' => now(),
            'reference' => 'PAY-CASH-FULL-LE',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $results = Sale::whereLiveDueAmountLessThanOrEqual(0)->get();
        $this->assertTrue($results->contains($sale), 'Fully cash-settled sale should be in zero live due scope');
    }

    /** @test */
    public function scope_whereLiveDueAmountGreaterThan_excludes_fully_credit_settled_sale()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-SETTLED',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Credit',
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'due_amount' => 0,
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-CREDIT',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 500000,
            'paid_amount' => 0,
            'due_amount' => 500000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Credit',
            'status' => 'APPROVED',
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 0,
            'date' => now(),
            'reference' => 'PAY-CREDIT-ONLY',
            'payment_method' => 'None',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 500000,
            'remaining_amount' => 0,
            'status' => 'closed',
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 500000,
        ]);

        $results = Sale::whereLiveDueAmountGreaterThan(0)->get();
        $this->assertFalse($results->contains($sale), 'Fully credit-settled sale should not be in positive live due scope');
    }

    /** @test */
    public function scope_whereLiveDueAmountLessThanOrEqual_includes_fully_credit_settled_sale()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-SETTLED-LE',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Credit',
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'due_amount' => 0,
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-CREDIT-LE',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 500000,
            'paid_amount' => 0,
            'due_amount' => 500000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Credit',
            'status' => 'APPROVED',
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 0,
            'date' => now(),
            'reference' => 'PAY-CREDIT-ONLY-LE',
            'payment_method' => 'None',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 500000,
            'remaining_amount' => 0,
            'status' => 'closed',
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 500000,
        ]);

        $results = Sale::whereLiveDueAmountLessThanOrEqual(0)->get();
        $this->assertTrue($results->contains($sale), 'Fully credit-settled sale should be in zero live due scope');
    }

    /** @test */
    public function scope_whereLiveDueAmountGreaterThan_excludes_fully_combined_cash_credit_settled_sale()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-MIXED-SETTLED',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Mixed',
            'total_amount' => 1000000,
            'paid_amount' => 1000000,
            'due_amount' => 0,
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-MIXED',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 400000,
            'paid_amount' => 0,
            'due_amount' => 400000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Mixed',
            'status' => 'APPROVED',
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 600000,
            'date' => now(),
            'reference' => 'PAY-CASH-PART',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 400000,
            'remaining_amount' => 0,
            'status' => 'closed',
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 400000,
        ]);

        $results = Sale::whereLiveDueAmountGreaterThan(0)->get();
        $this->assertFalse($results->contains($sale), 'Fully combined cash/credit settled sale should not be in positive live due scope');
    }

    /** @test */
    public function scope_whereLiveDueAmountLessThanOrEqual_includes_fully_combined_cash_credit_settled_sale()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-MIXED-SETTLED-LE',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Mixed',
            'total_amount' => 1000000,
            'paid_amount' => 1000000,
            'due_amount' => 0,
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-MIXED-LE',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 400000,
            'paid_amount' => 0,
            'due_amount' => 400000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Mixed',
            'status' => 'APPROVED',
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 600000,
            'date' => now(),
            'reference' => 'PAY-CASH-PART-LE',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 400000,
            'remaining_amount' => 0,
            'status' => 'closed',
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 400000,
        ]);

        $results = Sale::whereLiveDueAmountLessThanOrEqual(0)->get();
        $this->assertTrue($results->contains($sale), 'Fully combined cash/credit settled sale should be in zero live due scope');
    }

    /** @test */
    public function scope_whereLiveDueAmountGreaterThan_includes_partially_credit_settled_sale()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-PARTIAL-CREDIT',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Mixed',
            'total_amount' => 1000000,
            'paid_amount' => 300000,
            'due_amount' => 700000,
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-PARTIAL',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 200000,
            'paid_amount' => 0,
            'due_amount' => 200000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Mixed',
            'status' => 'APPROVED',
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 200000,
            'date' => now(),
            'reference' => 'PAY-PARTIAL-CREDIT',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 200000,
            'remaining_amount' => 100000,
            'status' => 'open',
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 100000,
        ]);

        $results = Sale::whereLiveDueAmountGreaterThan(0)->get();
        $this->assertTrue($results->contains($sale), 'Partially credit-settled sale should remain in positive live due scope');
        $this->assertEquals(700000, $sale->live_due_amount, 'Live due amount should equal the expected remainder');
    }

    /** @test */
    public function scope_whereLiveDueAmountGreaterThan_excludes_credit_on_invalidated_payment()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-INVALID-CREDIT',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Mixed',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-INVALID',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 300000,
            'paid_amount' => 0,
            'due_amount' => 300000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Mixed',
            'status' => 'APPROVED',
        ]);

        // Create invalidated payment with credit application
        $invalidPayment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 200000,
            'date' => now(),
            'reference' => 'PAY-INVALID',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_INVALIDATED,
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 300000,
            'remaining_amount' => 100000,
            'status' => 'open',
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $invalidPayment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 200000,
        ]);

        $results = Sale::whereLiveDueAmountGreaterThan(0)->get();
        $this->assertTrue($results->contains($sale), 'Sale with credit on invalidated payment should remain in positive live due scope');
        $this->assertEquals(1000000, $sale->live_due_amount, 'Live due amount should not be reduced by credit on invalidated payment');
    }

    /** @test */
    public function scope_ignores_stored_header_drift()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-DRIFT',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'PAID', // Deliberately incorrect
            'payment_method' => 'Cash',
            'total_amount' => 1000000,
            'paid_amount' => 1000000, // Deliberately incorrect (stale)
            'due_amount' => 0, // Deliberately incorrect (stale)
        ]);

        // In reality, only 100 has been paid
        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 100,
            'date' => now(),
            'reference' => 'PAY-ACTUAL',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $results = Sale::whereLiveDueAmountGreaterThan(0)->get();
        $this->assertTrue($results->contains($sale), 'Sale with stored header drift should be classified by active payments, not stored values');
        $this->assertEquals(999900, $sale->live_due_amount, 'Live due amount should come from active payments, not stored due_amount');
    }

    /** @test */
    public function scope_and_accessor_equivalence_for_cash_only()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CASH-EQUIV',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Cash',
            'total_amount' => 1000000,
            'paid_amount' => 300000,
            'due_amount' => 700000,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 300000,
            'date' => now(),
            'reference' => 'PAY-CASH',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        // Check accessor
        $accessorLiveDue = $sale->live_due_amount;
        $this->assertEquals(700000, $accessorLiveDue);

        // Check scope classification
        $inPositiveScope = Sale::whereLiveDueAmountGreaterThan(0)->where('id', $sale->id)->exists();
        $inZeroScope = Sale::whereLiveDueAmountLessThanOrEqual(0)->where('id', $sale->id)->exists();

        $this->assertTrue($inPositiveScope, 'Accessor and scope should agree: sale should be in positive scope');
        $this->assertFalse($inZeroScope, 'Accessor and scope should agree: sale should not be in zero scope');
    }

    /** @test */
    public function scope_and_accessor_equivalence_for_credit_only()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-EQUIV',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Credit',
            'total_amount' => 1000000,
            'paid_amount' => 200000,
            'due_amount' => 800000,
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-EQUIV',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 200000,
            'paid_amount' => 0,
            'due_amount' => 200000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Credit',
            'status' => 'APPROVED',
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 0,
            'date' => now(),
            'reference' => 'PAY-CREDIT-ONLY-EQUIV',
            'payment_method' => 'None',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 200000,
            'remaining_amount' => 0,
            'status' => 'closed',
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 200000,
        ]);

        // Check accessor
        $accessorLiveDue = $sale->live_due_amount;
        $this->assertEquals(800000, $accessorLiveDue);

        // Check scope classification
        $inPositiveScope = Sale::whereLiveDueAmountGreaterThan(0)->where('id', $sale->id)->exists();
        $inZeroScope = Sale::whereLiveDueAmountLessThanOrEqual(0)->where('id', $sale->id)->exists();

        $this->assertTrue($inPositiveScope, 'Accessor and scope should agree: sale should be in positive scope');
        $this->assertFalse($inZeroScope, 'Accessor and scope should agree: sale should not be in zero scope');
    }

    /** @test */
    public function scope_and_accessor_equivalence_for_mixed_payment()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-MIXED-EQUIV',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Mixed',
            'total_amount' => 1000000,
            'paid_amount' => 400000,
            'due_amount' => 600000,
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-MIXED-EQUIV',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 200000,
            'paid_amount' => 0,
            'due_amount' => 200000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Mixed',
            'status' => 'APPROVED',
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 300000,
            'date' => now(),
            'reference' => 'PAY-MIXED-EQUIV',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 200000,
            'remaining_amount' => 100000,
            'status' => 'open',
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 100000,
        ]);

        // Check accessor
        $accessorLiveDue = $sale->live_due_amount;
        $this->assertEquals(600000, $accessorLiveDue);

        // Check scope classification
        $inPositiveScope = Sale::whereLiveDueAmountGreaterThan(0)->where('id', $sale->id)->exists();
        $inZeroScope = Sale::whereLiveDueAmountLessThanOrEqual(0)->where('id', $sale->id)->exists();

        $this->assertTrue($inPositiveScope, 'Accessor and scope should agree: sale should be in positive scope');
        $this->assertFalse($inZeroScope, 'Accessor and scope should agree: sale should not be in zero scope');
    }

    /** @test */
    public function scope_and_accessor_equivalence_for_invalidated_credit()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-INVALID-EQUIV',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Mixed',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-INVALID-EQUIV',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 300000,
            'paid_amount' => 0,
            'due_amount' => 300000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Mixed',
            'status' => 'APPROVED',
        ]);

        $invalidPayment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 200000,
            'date' => now(),
            'reference' => 'PAY-INVALID-EQUIV',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_INVALIDATED,
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 300000,
            'remaining_amount' => 100000,
            'status' => 'open',
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $invalidPayment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 200000,
        ]);

        // Check accessor
        $accessorLiveDue = $sale->live_due_amount;
        $this->assertEquals(1000000, $accessorLiveDue);

        // Check scope classification
        $inPositiveScope = Sale::whereLiveDueAmountGreaterThan(0)->where('id', $sale->id)->exists();
        $inZeroScope = Sale::whereLiveDueAmountLessThanOrEqual(0)->where('id', $sale->id)->exists();

        $this->assertTrue($inPositiveScope, 'Accessor and scope should agree: sale should be in positive scope');
        $this->assertFalse($inZeroScope, 'Accessor and scope should agree: sale should not be in zero scope');
    }
}
