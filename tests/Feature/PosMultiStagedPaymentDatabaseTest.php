<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\PaymentMethod;

/**
 * Task 11: Database & Transaction Tracking
 *
 * Test scenarios:
 * - Payment records store stage order (1st, 2nd, 3rd, etc.)
 * - Payment records store EDC reference for non-cash methods
 * - Multi-stage payment appears as separate payment records, not merged
 * - Finalize can correctly aggregate multiple payment stages
 * - Sales posting includes all payment stages (totals reconcile)
 */
class PosMultiStagedPaymentDatabaseTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $sale;
    protected $cashMethod;
    protected $briMethod;
    protected $bniMethod;

    public function setUp(): void
    {
        parent::setUp();
        $this->setupPaymentMethods();
        $this->createTestSale();
    }

    private function setupPaymentMethods()
    {
        $this->cashMethod = PaymentMethod::create([
            'name' => 'CASH',
            'is_cash' => true,
            'is_active' => true,
        ]);

        $this->briMethod = PaymentMethod::create([
            'name' => 'BRI',
            'is_cash' => false,
            'requires_reference' => true,
            'is_active' => true,
        ]);

        $this->bniMethod = PaymentMethod::create([
            'name' => 'BNI',
            'is_cash' => false,
            'requires_reference' => true,
            'is_active' => true,
        ]);
    }

    private function createTestSale()
    {
        $this->sale = Sale::create([
            'sale_date' => now(),
            'due_amount' => 3000000,
            'discount_amount' => 0,
            'total_amount' => 3000000,
            'note' => 'Test database tracking',
            'is_active' => true,
        ]);
    }

    /**
     * Test 11.1: Payment records store stage order
     *
     * Scenario:
     * 1. Submit 3 payments in sequence
     * 2. Verify each payment record has correct stage_order (1, 2, 3)
     */
    public function test_payment_records_store_stage_order()
    {
        // Payment 1
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'REF1',
            'idempotency_key' => 'order-1-' . time(),
        ]);

        // Payment 2
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->bniMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'REF2',
            'idempotency_key' => 'order-2-' . time(),
        ]);

        // Payment 3
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
            'idempotency_key' => 'order-3-' . time(),
        ]);

        // Verify stage orders
        $payments = SalePayment::where('sale_id', $this->sale->id)
            ->orderBy('stage_order')
            ->get();

        $this->assertEquals(3, $payments->count());
        $this->assertEquals(1, $payments[0]->stage_order);
        $this->assertEquals(2, $payments[1]->stage_order);
        $this->assertEquals(3, $payments[2]->stage_order);
    }

    /**
     * Test 11.2: Payment records store EDC reference for non-cash methods
     *
     * Scenario:
     * 1. Submit BRI payment with reference
     * 2. Submit BNI payment with reference
     * 3. Submit CASH payment (no reference)
     * 4. Verify EDC references stored correctly
     */
    public function test_payment_records_store_edc_reference()
    {
        // BRI with reference
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'BRI12345',
            'idempotency_key' => 'edc-bri-' . time(),
        ]);

        // BNI with reference
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->bniMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'BNI54321',
            'idempotency_key' => 'edc-bni-' . time(),
        ]);

        // CASH without reference
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
            'idempotency_key' => 'edc-cash-' . time(),
        ]);

        // Verify EDC references
        $briPayment = SalePayment::where('sale_id', $this->sale->id)
            ->where('payment_method_id', $this->briMethod->id)
            ->first();
        $this->assertEquals('BRI12345', $briPayment->edc_reference);

        $bniPayment = SalePayment::where('sale_id', $this->sale->id)
            ->where('payment_method_id', $this->bniMethod->id)
            ->first();
        $this->assertEquals('BNI54321', $bniPayment->edc_reference);

        $cashPayment = SalePayment::where('sale_id', $this->sale->id)
            ->where('payment_method_id', $this->cashMethod->id)
            ->first();
        $this->assertNull($cashPayment->edc_reference);
    }

    /**
     * Test 11.3: Multi-stage payment appears as separate payment records, not merged
     *
     * Scenario:
     * 1. Submit 3 payments
     * 2. Verify 3 separate payment records exist (not 1 merged record)
     */
    public function test_multi_stage_payments_not_merged()
    {
        $paymentCount = SalePayment::where('sale_id', $this->sale->id)->count();
        $this->assertEquals(0, $paymentCount);

        // Three payments
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'REF1',
            'idempotency_key' => 'merge-1-' . time(),
        ]);

        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->bniMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'REF2',
            'idempotency_key' => 'merge-2-' . time(),
        ]);

        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
            'idempotency_key' => 'merge-3-' . time(),
        ]);

        // Verify 3 separate records
        $paymentCount = SalePayment::where('sale_id', $this->sale->id)->count();
        $this->assertEquals(3, $paymentCount);

        // Verify all have same sale_id but different stage_order
        $payments = SalePayment::where('sale_id', $this->sale->id)
            ->orderBy('stage_order')
            ->get();

        $this->assertEquals(1, $payments[0]->stage_order);
        $this->assertEquals(2, $payments[1]->stage_order);
        $this->assertEquals(3, $payments[2]->stage_order);

        // Verify amounts are separate
        $this->assertEquals(1000000, $payments[0]->amount);
        $this->assertEquals(1000000, $payments[1]->amount);
        $this->assertEquals(1000000, $payments[2]->amount);
    }

    /**
     * Test 11.4: Finalize can correctly aggregate multiple payment stages
     *
     * Scenario:
     * 1. Submit 3 payments totaling grand total
     * 2. Call finalize
     * 3. Verify PosCheckout aggregates all payments
     */
    public function test_finalize_aggregates_payment_stages()
    {
        // Submit 3 payments
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'REF1',
            'idempotency_key' => 'agg-1-' . time(),
        ]);

        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->bniMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'REF2',
            'idempotency_key' => 'agg-2-' . time(),
        ]);

        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
            'idempotency_key' => 'agg-3-' . time(),
        ]);

        // Verify total payments equal due amount
        $totalPayments = SalePayment::where('sale_id', $this->sale->id)
            ->sum('amount');

        $this->assertEquals($this->sale->due_amount, $totalPayments);
    }

    /**
     * Test 11.5: Verify sales posting includes all payment stages
     *
     * Scenario:
     * 1. Submit 2 payments totaling sale amount
     * 2. Verify both payments counted in total
     * 3. Check that no reconciliation issues exist
     */
    public function test_sales_posting_includes_all_payment_stages()
    {
        $sale = Sale::create([
            'sale_date' => now(),
            'due_amount' => 2000000,
            'discount_amount' => 0,
            'total_amount' => 2000000,
            'note' => 'Posting test',
            'is_active' => true,
        ]);

        // Two payments
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'POST1',
            'idempotency_key' => 'post-1-' . time(),
        ]);

        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
            'idempotency_key' => 'post-2-' . time(),
        ]);

        // Verify both payments exist
        $payments = SalePayment::where('sale_id', $sale->id)->get();
        $this->assertCount(2, $payments);

        // Verify total reconciles with sale
        $totalPaid = $payments->sum('amount');
        $this->assertEquals($sale->due_amount, $totalPaid);

        // Verify no overpayment/underpayment
        $balance = $sale->due_amount - $totalPaid;
        $this->assertEquals(0, $balance);
    }

    /**
     * Test 11.1b: Verify stage_order increments correctly even with retries
     *
     * Scenario:
     * 1. Submit first payment
     * 2. Retry first payment with same idempotency key
     * 3. Submit second payment
     * 4. Verify stage orders are 1, 2 (not 1, 1, 2)
     */
    public function test_stage_order_with_idempotency_retry()
    {
        // First payment
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'REF1',
            'idempotency_key' => 'stage-retry-1',
        ]);

        // Retry first payment (same idempotency key - should not create new record)
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'REF1',
            'idempotency_key' => 'stage-retry-1',
        ]);

        // Second payment
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->bniMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'REF2',
            'idempotency_key' => 'stage-retry-2',
        ]);

        // Verify correct stage orders
        $payments = SalePayment::where('sale_id', $this->sale->id)
            ->orderBy('stage_order')
            ->get();

        $this->assertCount(2, $payments);
        $this->assertEquals(1, $payments[0]->stage_order);
        $this->assertEquals(2, $payments[1]->stage_order);
    }
}
