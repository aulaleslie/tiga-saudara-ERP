<?php

namespace Modules\Sale\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Services\GlobalSalePaymentService;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class GlobalSalePaymentServiceTest extends TestCase
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
    public function service_rejects_all_zero_allocations()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-ZERO',
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

        $this->expectException(ValidationException::class);
        $this->service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale->id => 0],
            'date' => now()->toDateString(),
            'reference' => 'PAY-ZERO',
            'payment_method_id' => $this->paymentMethod->id,
        ]);
    }

    /** @test */
    public function service_rejects_negative_allocations()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-NEG',
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

        $this->expectException(ValidationException::class);
        $this->service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale->id => -50000],
            'date' => now()->toDateString(),
            'reference' => 'PAY-NEG',
            'payment_method_id' => $this->paymentMethod->id,
        ]);
    }

    /** @test */
    public function service_accepts_mixed_positive_and_zero_allocations()
    {
        $sale1 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-MIX-1',
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
            'reference' => 'SALE-MIX-2',
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

        $payments = $this->service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale1->id => 50000, $sale2->id => 0],
            'date' => now()->toDateString(),
            'reference' => 'PAY-MIX',
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        $this->assertCount(1, $payments, 'Only positive allocations should create payments');
        $this->assertEquals(50000, $payments[0]->amount);
    }

    /** @test */
    public function service_rejects_sub_cent_allocation_that_normalizes_to_zero()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-SUBCENTNORM',
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

        $this->expectException(ValidationException::class);
        $this->service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale->id => 0.001],
            'date' => now()->toDateString(),
            'reference' => 'PAY-SUBCENT',
            'payment_method_id' => $this->paymentMethod->id,
        ]);
    }

    /** @test */
    public function service_accepts_exact_two_decimal_allocation()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-EXACT',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 123.45,
            'paid_amount' => 0,
            'due_amount' => 123.45,
        ]);

        $payments = $this->service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale->id => 123.45],
            'date' => now()->toDateString(),
            'reference' => 'PAY-EXACT',
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        $this->assertCount(1, $payments);
        $this->assertEquals(123.45, $payments[0]->amount);
    }

    /** @test */
    public function service_rejects_non_numeric_allocation()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-NONNUMERIC',
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

        $this->expectException(ValidationException::class);
        $this->service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale->id => 'not-a-number'],
            'date' => now()->toDateString(),
            'reference' => 'PAY-NONNUMERIC',
            'payment_method_id' => $this->paymentMethod->id,
        ]);
    }

    /** @test */
    public function service_rejects_overpayment()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-OVERPAY',
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

        $this->expectException(ValidationException::class);
        $this->service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale->id => 100000.01],
            'date' => now()->toDateString(),
            'reference' => 'PAY-OVERPAY',
            'payment_method_id' => $this->paymentMethod->id,
        ]);
    }

    /** @test */
    public function service_accepts_partial_payment()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-PARTIAL',
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

        $payments = $this->service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale->id => 50000],
            'date' => now()->toDateString(),
            'reference' => 'PAY-PARTIAL',
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        $this->assertCount(1, $payments);
        $this->assertEquals(50000, $payments[0]->amount);

        $sale->refresh();
        $this->assertEquals(50000, $sale->live_due_amount);
    }

    /** @test */
    public function service_creates_payment_for_each_positive_allocation()
    {
        $sale1 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-MULTI-1',
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
            'reference' => 'SALE-MULTI-2',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 200000,
            'paid_amount' => 0,
            'due_amount' => 200000,
        ]);

        $payments = $this->service->storeMultiPayment($this->customer->id, [
            'allocations' => [$sale1->id => 40000, $sale2->id => 80000],
            'date' => now()->toDateString(),
            'reference' => 'PAY-MULTI',
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        $this->assertCount(2, $payments);
        $this->assertEquals(40000, $payments[0]->amount);
        $this->assertEquals(80000, $payments[1]->amount);

        $sale1->refresh();
        $sale2->refresh();
        $this->assertEquals(60000, $sale1->live_due_amount);
        $this->assertEquals(120000, $sale2->live_due_amount);
    }

    /** @test */
    public function service_rejects_batch_with_mixed_positive_and_negative_allocations()
    {
        $saleA = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-MIXED-POS-NEG-A',
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

        $saleB = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SALE-MIXED-POS-NEG-B',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 200000,
            'paid_amount' => 0,
            'due_amount' => 200000,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->storeMultiPayment($this->customer->id, [
            'allocations' => [$saleA->id => 50000, $saleB->id => -30000],
            'date' => now()->toDateString(),
            'reference' => 'PAY-MIXED-POS-NEG',
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        // Verify no payments were created for either sale
        $this->assertEquals(0, SalePayment::count());

        // Verify sale headers unchanged
        $saleA->refresh();
        $saleB->refresh();
        $this->assertEquals(100000, $saleA->live_due_amount);
        $this->assertEquals(200000, $saleB->live_due_amount);
    }
}
