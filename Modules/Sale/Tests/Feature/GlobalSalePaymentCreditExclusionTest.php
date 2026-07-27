<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Services\GlobalSalePaymentService;
use Modules\SalesReturn\Entities\CustomerCredit;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Tests that global sale payment workflow excludes customer-credit operations.
 *
 * Global payments are monetary-only and do not create SalePaymentCreditApplication
 * records or mutate CustomerCredit state. This isolates the global multi-payment
 * workflow from the independent single-sale customer-credit system.
 */
class GlobalSalePaymentCreditExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;
    protected PaymentMethod $paymentMethod;
    protected GlobalSalePaymentService $service;

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

        $this->service = new GlobalSalePaymentService();
    }

    /** @test */
    public function global_service_creates_no_credit_application()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-NOMUT',
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

        // Create a customer credit for this customer
        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-NOMUT',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        $credit = CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 50000,
            'remaining_amount' => 50000,
            'status' => 'open',
        ]);

        $originalRemainingAmount = $credit->remaining_amount;
        $originalStatus = $credit->status;

        // Submit global payment without credit
        $this->service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale->id => 50000],
            'date' => now()->toDateString(),
            'reference' => 'PAY-NOMUT',
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        // Verify ordinary payment was created
        $this->assertEquals(1, SalePayment::count());
        $this->assertEquals(50000, SalePayment::first()->amount);

        // Verify open credit remains unchanged
        $credit->refresh();
        $this->assertEquals($originalRemainingAmount, $credit->remaining_amount);
        $this->assertEquals($originalStatus, $credit->status);

        // Verify no credit applications were created
        $this->assertEquals(0, \Modules\SalesReturn\Entities\SalePaymentCreditApplication::count());
    }

    /** @test */
    public function global_service_does_not_query_or_mutate_customer_credit()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-CREDIT-ISOLATION',
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

        // Create multiple customer credits
        $saleReturn1 = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-1',
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

        $saleReturn2 = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-2',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => 40000,
            'paid_amount' => 0,
            'due_amount' => 40000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        $credit1 = CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn1->id,
            'amount' => 30000,
            'remaining_amount' => 30000,
            'status' => 'open',
        ]);

        $credit2 = CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn2->id,
            'amount' => 40000,
            'remaining_amount' => 40000,
            'status' => 'open',
        ]);

        $originalRemainingAmount1 = $credit1->remaining_amount;
        $originalRemainingAmount2 = $credit2->remaining_amount;

        // Submit global payment for only 50k of the 100k due
        // If the service were to consult credits, it might try to satisfy the remaining 50k
        $this->service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale->id => 50000],
            'date' => now()->toDateString(),
            'reference' => 'PAY-ISOLATION',
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        // Verify payment was created for monetary amount only
        $this->assertEquals(1, SalePayment::count());
        $payment = SalePayment::first();
        $this->assertEquals(50000, $payment->amount);

        // Verify both credits unchanged
        $credit1->refresh();
        $credit2->refresh();
        $this->assertEquals($originalRemainingAmount1, $credit1->remaining_amount);
        $this->assertEquals($originalRemainingAmount2, $credit2->remaining_amount);

        // Verify sale still has 50k due (credit not applied)
        $sale->refresh();
        $this->assertEquals(50000, $sale->live_due_amount);
    }
}
