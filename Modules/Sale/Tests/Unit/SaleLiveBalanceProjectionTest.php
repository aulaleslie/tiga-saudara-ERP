<?php

namespace Modules\Sale\Tests\Unit;

use Tests\TestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SalePaymentCreditApplication;
use Modules\SalesReturn\Entities\CustomerCredit;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\People\Entities\Customer;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SaleLiveBalanceProjectionTest extends TestCase
{
    use RefreshDatabase;

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

        Setting::create([
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
            'setting_id' => 1,
        ]);
    }

    protected function createSale(array $overrides = []): Sale
    {
        return Sale::create(array_merge([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'SO-' . uniqid(),
            'setting_id' => 1,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'payment_method' => 'Cash',
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ], $overrides));
    }

    protected function createCredit(float $amount): CustomerCredit
    {
        $saleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RET-' . uniqid(),
            'setting_id' => 1,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'total_amount' => $amount,
            'paid_amount' => 0,
            'due_amount' => $amount,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        return CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'status' => 'open',
        ]);
    }

    /** @test */
    public function it_calculates_live_balance_with_no_payments(): void
    {
        $sale = $this->createSale([
            'reference' => 'SO-001',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        $this->assertEquals(0, $sale->getEffectivePaidAmount());
        $this->assertEquals(1000000, $sale->live_due_amount);

        $this->assertTrue(Sale::whereLiveDueAmountGreaterThan(0)->where('id', $sale->id)->exists());
        $this->assertFalse(Sale::whereLiveDueAmountLessThanOrEqual(0)->where('id', $sale->id)->exists());
    }

    /** @test */
    public function it_calculates_live_balance_with_active_payments_and_credits_ignoring_stale_headers(): void
    {
        $sale = $this->createSale([
            'reference' => 'SO-002',
            'total_amount' => 1000000,
            'paid_amount' => 100000, // Deliberately stale
            'due_amount' => 900000,  // Deliberately stale
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 300000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $credit = $this->createCredit(500000);

        SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 200000,
        ]);

        // Standalone query path
        $fresh = Sale::findOrFail($sale->id);
        $this->assertEquals(500000, $fresh->getEffectivePaidAmount());
        $this->assertEquals(500000, $fresh->live_due_amount);
        $this->assertEquals(900000, $fresh->due_amount); // Stored untouched

        // Preloaded scopeWithLiveSettlementAggregates path
        $preloaded = Sale::withLiveSettlementAggregates()->findOrFail($sale->id);
        $this->assertEquals(500000, $preloaded->getEffectivePaidAmount());
        $this->assertEquals(500000, $preloaded->live_due_amount);

        // SQL predicates
        $this->assertTrue(Sale::whereLiveDueAmountGreaterThan(400000)->where('id', $sale->id)->exists());
        $this->assertFalse(Sale::whereLiveDueAmountGreaterThan(500000)->where('id', $sale->id)->exists());
        $this->assertTrue(Sale::whereLiveDueAmountLessThanOrEqual(500000)->where('id', $sale->id)->exists());
    }

    /** @test */
    public function it_excludes_invalidated_payments_and_their_credits(): void
    {
        $sale = $this->createSale([
            'reference' => 'SO-003',
            'total_amount' => 1000000,
            'paid_amount' => 500000, // Deliberately stale
            'due_amount' => 500000,
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 300000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-002',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_INVALIDATED,
        ]);

        $credit = $this->createCredit(500000);

        SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 200000,
        ]);

        $fresh = Sale::findOrFail($sale->id);
        $this->assertEquals(0, $fresh->getEffectivePaidAmount());
        $this->assertEquals(1000000, $fresh->live_due_amount);

        $preloaded = Sale::withLiveSettlementAggregates()->findOrFail($sale->id);
        $this->assertEquals(0, $preloaded->getEffectivePaidAmount());
        $this->assertEquals(1000000, $preloaded->live_due_amount);
    }

    /** @test */
    public function it_handles_fractional_currency_precision_correctly(): void
    {
        $sale = $this->createSale([
            'reference' => 'SO-004',
            'total_amount' => 1000.55,
            'paid_amount' => 0,
            'due_amount' => 1000.55,
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 500.25,
            'date' => now()->toDateString(),
            'reference' => 'PAY-003',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $credit = $this->createCredit(500);

        SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 200.10,
        ]);

        $fresh = Sale::findOrFail($sale->id);
        // Paid: 500.25 + 200.10 = 700.35. Due: 1000.55 - 700.35 = 300.20
        $this->assertEquals(700.35, $fresh->getEffectivePaidAmount());
        $this->assertEquals(300.20, $fresh->live_due_amount);

        $preloaded = Sale::withLiveSettlementAggregates()->findOrFail($sale->id);
        $this->assertEquals(700.35, $preloaded->getEffectivePaidAmount());
        $this->assertEquals(300.20, $preloaded->live_due_amount);
    }

    /** @test */
    public function it_clamps_live_due_to_zero_on_over_settlement_without_modifying_records(): void
    {
        $sale = $this->createSale([
            'reference' => 'SO-005',
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'total_amount' => 500000,
            'paid_amount' => 600000,
            'due_amount' => 0,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 600000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-004',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $fresh = Sale::findOrFail($sale->id);
        $this->assertEquals(600000, $fresh->getEffectivePaidAmount());
        $this->assertEquals(0, $fresh->live_due_amount);

        $preloaded = Sale::withLiveSettlementAggregates()->findOrFail($sale->id);
        $this->assertEquals(600000, $preloaded->getEffectivePaidAmount());
        $this->assertEquals(0, $preloaded->live_due_amount);

        $this->assertFalse(Sale::whereLiveDueAmountGreaterThan(0)->where('id', $sale->id)->exists());
        $this->assertTrue(Sale::whereLiveDueAmountLessThanOrEqual(0)->where('id', $sale->id)->exists());
    }

    /** @test */
    public function it_orders_deterministically_by_clamped_live_due_with_opposite_stored_ordering(): void
    {
        // Sale A: stored due 100k, live due 800k (total 1M, active pay 200k)
        $saleA = $this->createSale([
            'reference' => 'SO-A',
            'payment_status' => 'Partial',
            'total_amount' => 1000000,
            'paid_amount' => 900000,
            'due_amount' => 100000, // Stored smaller
        ]);
        SalePayment::create([
            'sale_id' => $saleA->id,
            'amount' => 200000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-A',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        // Sale B: stored due 900k, live due 200k (total 1M, active pay 800k)
        $saleB = $this->createSale([
            'reference' => 'SO-B',
            'payment_status' => 'Partial',
            'total_amount' => 1000000,
            'paid_amount' => 100000,
            'due_amount' => 900000, // Stored larger
        ]);
        SalePayment::create([
            'sale_id' => $saleB->id,
            'amount' => 800000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-B',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        // Sale C: over-settled (total 500k, active pay 600k, clamped live due 0)
        $saleC = $this->createSale([
            'reference' => 'SO-C',
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'total_amount' => 500000,
            'paid_amount' => 600000,
            'due_amount' => 0,
        ]);
        SalePayment::create([
            'sale_id' => $saleC->id,
            'amount' => 600000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-C',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        // Order ascending: SO-C (0) -> SO-B (200k) -> SO-A (800k)
        $ascIds = Sale::whereIn('id', [$saleA->id, $saleB->id, $saleC->id])
            ->orderByLiveDueAmount('asc')
            ->orderBy('id', 'asc')
            ->pluck('id')
            ->toArray();

        $this->assertEquals([$saleC->id, $saleB->id, $saleA->id], $ascIds);

        // Order descending: SO-A (800k) -> SO-B (200k) -> SO-C (0)
        $descIds = Sale::whereIn('id', [$saleA->id, $saleB->id, $saleC->id])
            ->orderByLiveDueAmount('desc')
            ->orderBy('id', 'asc')
            ->pluck('id')
            ->toArray();

        $this->assertEquals([$saleA->id, $saleB->id, $saleC->id], $descIds);
    }
}
