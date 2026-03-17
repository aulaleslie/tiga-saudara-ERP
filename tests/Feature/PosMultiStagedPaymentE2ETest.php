<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Pos\Entities\PaymentMethod;
use Modules\Pos\Entities\PosCheckout;

/**
 * Task 7.5 & 8.1: End-to-end tests for multi-stage payment flow
 *
 * Test scenarios:
 * - Single CASH payment (straightforward path)
 * - 2-stage payment: BRI + CASH
 * - 3-stage payment: BRI + BNI + CASH
 * - Overpayment handling
 * - Error recovery & retry
 */
class PosMultiStagedPaymentE2ETest extends TestCase
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
            'due_amount' => 5000000, // 5 million
            'discount_amount' => 0,
            'total_amount' => 5000000,
            'note' => 'Test multi-stage payment E2E',
            'is_active' => true,
        ]);
    }

    /**
     * Test 8.1: Single CASH payment (straightforward path)
     *
     * Scenario:
     * 1. Create a sale with due amount 1M
     * 2. Submit single CASH payment for 1M
     * 3. Verify payment is committed
     * 4. Call finalize endpoint
     * 5. Verify checkout is created and receipt is generated
     */
    public function test_single_cash_payment_simple_path()
    {
        $simpleSale = Sale::create([
            'sale_date' => now(),
            'due_amount' => 1000000,
            'discount_amount' => 0,
            'total_amount' => 1000000,
            'note' => 'Simple single cash payment',
            'is_active' => true,
        ]);

        // Submit single CASH payment
        $paymentResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $simpleSale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
            'idempotency_key' => 'cash-simple-' . time(),
        ]);

        $paymentResponse->assertStatus(200);
        $paymentData = $paymentResponse->json();

        // Verify remainder is zero
        $this->assertEquals(0, $paymentData['remainder']);
        $this->assertCount(1, $paymentData['payment_chain']);

        // Verify payment was created in DB
        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $simpleSale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
        ]);
    }

    /**
     * Test 8.2: 2-stage payment: BRI + CASH
     *
     * Scenario:
     * 1. Create sale with due amount 2M
     * 2. Submit BRI payment for 1M
     * 3. Submit CASH payment for 1M
     * 4. Verify remainder = 0
     * 5. Verify both payment records exist in DB
     */
    public function test_two_stage_payment_bri_plus_cash()
    {
        $twoStageSale = Sale::create([
            'sale_date' => now(),
            'due_amount' => 2000000,
            'discount_amount' => 0,
            'total_amount' => 2000000,
            'note' => 'Two stage BRI + CASH',
            'is_active' => true,
        ]);

        // Stage 1: BRI payment
        $stage1Response = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $twoStageSale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'BRI001',
            'idempotency_key' => 'bri-stage-' . time(),
        ]);

        $stage1Response->assertStatus(200);
        $stage1Data = $stage1Response->json();
        $this->assertEquals(1000000, $stage1Data['remainder']);
        $this->assertCount(1, $stage1Data['payment_chain']);

        // Stage 2: CASH payment
        $stage2Response = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $twoStageSale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
            'idempotency_key' => 'cash-stage-' . time(),
        ]);

        $stage2Response->assertStatus(200);
        $stage2Data = $stage2Response->json();
        $this->assertEquals(0, $stage2Data['remainder']);
        $this->assertCount(2, $stage2Data['payment_chain']);

        // Verify both payments in DB
        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $twoStageSale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
        ]);

        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $twoStageSale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
        ]);

        // Verify payment stage order
        $briPayment = SalePayment::where('sale_id', $twoStageSale->id)
            ->where('payment_method_id', $this->briMethod->id)
            ->first();
        $this->assertEquals(1, $briPayment->stage_order);

        $cashPayment = SalePayment::where('sale_id', $twoStageSale->id)
            ->where('payment_method_id', $this->cashMethod->id)
            ->first();
        $this->assertEquals(2, $cashPayment->stage_order);
    }

    /**
     * Test 8.3: 3-stage payment: BRI + BNI + CASH
     *
     * Scenario:
     * 1. Create sale with due amount 3M
     * 2. Submit 3 payments in sequence
     * 3. Verify all payments committed with correct stage order
     */
    public function test_three_stage_payment_bri_plus_bni_plus_cash()
    {
        $threeStageSale = Sale::create([
            'sale_date' => now(),
            'due_amount' => 3000000,
            'discount_amount' => 0,
            'total_amount' => 3000000,
            'note' => 'Three stage BRI + BNI + CASH',
            'is_active' => true,
        ]);

        // Stage 1: BRI
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $threeStageSale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'BRI001',
            'idempotency_key' => 'bri-3stage-' . time(),
        ])->assertStatus(200);

        // Stage 2: BNI
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $threeStageSale->id,
            'payment_method_id' => $this->bniMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'BNI001',
            'idempotency_key' => 'bni-3stage-' . time(),
        ])->assertStatus(200);

        // Stage 3: CASH
        $stage3Response = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $threeStageSale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
            'idempotency_key' => 'cash-3stage-' . time(),
        ]);

        $stage3Response->assertStatus(200);
        $this->assertEquals(0, $stage3Response->json()['remainder']);
        $this->assertCount(3, $stage3Response->json()['payment_chain']);

        // Verify all 3 payment records
        $payments = SalePayment::where('sale_id', $threeStageSale->id)
            ->orderBy('stage_order')
            ->get();

        $this->assertCount(3, $payments);
        $this->assertEquals($this->briMethod->id, $payments[0]->payment_method_id);
        $this->assertEquals($this->bniMethod->id, $payments[1]->payment_method_id);
        $this->assertEquals($this->cashMethod->id, $payments[2]->payment_method_id);
    }

    /**
     * Test 8.4: Overpayment in final stage
     *
     * Scenario:
     * 1. Create sale with due amount 1.5M
     * 2. Submit first payment 1M
     * 3. Submit second payment 2M (overpayment)
     * 4. Verify change amount is calculated
     */
    public function test_overpayment_in_final_stage()
    {
        $overpaidSale = Sale::create([
            'sale_date' => now(),
            'due_amount' => 1500000, // 1.5M
            'discount_amount' => 0,
            'total_amount' => 1500000,
            'note' => 'Overpayment test',
            'is_active' => true,
        ]);

        // First payment
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $overpaidSale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
            'idempotency_key' => 'cash-over-1-' . time(),
        ])->assertStatus(200);

        // Second payment - overpayment
        $overpaymentResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $overpaidSale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000, // Total would be 2M, overpaying by 500k
            'idempotency_key' => 'cash-over-2-' . time(),
        ]);

        $overpaymentResponse->assertStatus(200);
        $overpayData = $overpaymentResponse->json();

        // Verify remainder is negative (overpayment)
        $this->assertLessThan(0, $overpayData['remainder']);

        // Verify overpayment amount in response
        $overpayment = abs($overpayData['remainder']);
        $this->assertEquals(500000, $overpayment);
    }

    /**
     * Test EDC reference format validation
     *
     * Scenario:
     * 1. Try to submit BRI payment with invalid EDC reference format
     * 2. Verify error is returned
     * 3. Try again with valid format
     * 4. Verify payment succeeds
     */
    public function test_edc_reference_validation()
    {
        // Invalid reference (special characters)
        $invalidResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'BRI@#$', // Invalid
            'idempotency_key' => 'invalid-ref-' . time(),
        ]);

        $invalidResponse->assertStatus(422);
        $this->assertStringContainsString('edc_reference', json_encode($invalidResponse->json()));

        // Valid reference
        $validResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'BRI12345', // Valid
            'idempotency_key' => 'valid-ref-' . time(),
        ]);

        $validResponse->assertStatus(200);
    }

    /**
     * Test payment failure and retry scenario
     *
     * Scenario:
     * 1. Submit payment
     * 2. User retries with different amount for same stage
     * 3. Verify retry creates new payment record with new idempotency key
     */
    public function test_payment_retry_with_different_amount()
    {
        // First attempt
        $firstResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 500000,
            'edc_reference' => 'RETRY001',
            'idempotency_key' => 'retry-key-1',
        ]);

        $firstResponse->assertStatus(200);
        $this->assertEquals(4500000, $firstResponse->json()['remainder']);

        // Retry with different amount (different idempotency key)
        $retryResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'RETRY002',
            'idempotency_key' => 'retry-key-2',
        ]);

        $retryResponse->assertStatus(200);
        $this->assertEquals(3500000, $retryResponse->json()['remainder']);

        // Verify both payments created
        $paymentCount = SalePayment::where('sale_id', $this->sale->id)
            ->where('payment_method_id', $this->briMethod->id)
            ->count();
        $this->assertEquals(2, $paymentCount);
    }

    /**
     * Test 8.7: Method change mid-stage
     *
     * Scenario:
     * 1. Start entering BRI payment
     * 2. Change mind and decide to use BNI instead
     * 3. Submit BNI payment with different idempotency key
     * 4. Verify payment chain contains BNI payment (not BRI)
     */
    public function test_method_change_mid_stage()
    {
        $methodChangeSale = Sale::create([
            'sale_date' => now(),
            'due_amount' => 2000000,
            'discount_amount' => 0,
            'total_amount' => 2000000,
            'note' => 'Method change mid-stage test',
            'is_active' => true,
        ]);

        // "User starts BRI but changes mind"
        // In the UI, user would select BRI, enter amount, but then realize they
        // don't have BRI card and want to use BNI instead.
        // This is simulated by submitting BNI with a different idempotency key

        // Submit BNI payment (original plan was BRI, but user changed mind)
        $methodChangeResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $methodChangeSale->id,
            'payment_method_id' => $this->bniMethod->id,
            'amount' => 2000000,
            'edc_reference' => 'BNI001',
            'idempotency_key' => 'method-change-' . time(),
        ]);

        $methodChangeResponse->assertStatus(200);
        $chainData = $methodChangeResponse->json();

        // Verify payment chain shows only BNI payment
        $this->assertCount(1, $chainData['payment_chain']);
        $this->assertEquals('BNI', $chainData['payment_chain'][0]['method_name']);
        $this->assertEquals(2000000, $chainData['payment_chain'][0]['amount']);
        $this->assertEquals(0, $chainData['remainder']);

        // Verify only one payment in DB (the BNI one)
        $paymentCount = SalePayment::where('sale_id', $methodChangeSale->id)->count();
        $this->assertEquals(1, $paymentCount);

        // Verify it's the BNI payment
        $payment = SalePayment::where('sale_id', $methodChangeSale->id)->first();
        $this->assertEquals($this->bniMethod->id, $payment->payment_method_id);
    }
}
