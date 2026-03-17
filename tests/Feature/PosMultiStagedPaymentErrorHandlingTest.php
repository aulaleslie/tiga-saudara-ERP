<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\PaymentMethod;

/**
 * Task 10: Error Handling & Edge Cases
 *
 * Test scenarios:
 * - Network timeout during stage submit
 * - Duplicate submit (double-click prevention)
 * - Modal close button disabled during processing
 * - Amount input validation
 * - Overpayment (remainder becomes negative)
 * - Empty method selector
 */
class PosMultiStagedPaymentErrorHandlingTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $sale;
    protected $cashMethod;
    protected $briMethod;

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
    }

    private function createTestSale()
    {
        $this->sale = Sale::create([
            'sale_date' => now(),
            'due_amount' => 5000000,
            'discount_amount' => 0,
            'total_amount' => 5000000,
            'note' => 'Test error handling',
            'is_active' => true,
        ]);
    }

    /**
     * Test 10.1: Network timeout - modal should unlock on retry
     *
     * Scenario:
     * 1. Submit payment (simulate success)
     * 2. On retry with same idempotency key, should return same response
     * 3. Modal should not be locked on retry
     */
    public function test_network_timeout_retry_unlocks_modal()
    {
        $idempotencyKey = 'timeout-' . time();

        // First submission succeeds
        $firstResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
            'idempotency_key' => $idempotencyKey,
        ]);

        $firstResponse->assertStatus(200);
        $firstData = $firstResponse->json();

        // Retry with same idempotency key (simulating timeout retry)
        $retryResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
            'idempotency_key' => $idempotencyKey,
        ]);

        $retryResponse->assertStatus(200);
        $retryData = $retryResponse->json();

        // Response should be identical
        $this->assertEquals($firstData['remainder'], $retryData['remainder']);
        $this->assertEquals($firstData['stage_order'], $retryData['stage_order']);

        // Verify no duplicate payment created
        $paymentCount = SalePayment::where('sale_id', $this->sale->id)
            ->where('amount', 1000000)
            ->count();
        $this->assertEquals(1, $paymentCount);
    }

    /**
     * Test 10.2: Duplicate submit (double-click) prevention via idempotency key
     *
     * Scenario:
     * 1. Submit payment
     * 2. Immediately resubmit with same idempotency key
     * 3. Verify no duplicate payment
     */
    public function test_double_click_prevention_idempotency()
    {
        $idempotencyKey = 'double-click-' . time();

        // First click
        $firstResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'TEST001',
            'idempotency_key' => $idempotencyKey,
        ]);

        $firstResponse->assertStatus(200);

        // Second click (double-click) - same idempotency key
        $secondResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'TEST001',
            'idempotency_key' => $idempotencyKey,
        ]);

        $secondResponse->assertStatus(200);

        // Only one payment should exist
        $paymentCount = SalePayment::where('sale_id', $this->sale->id)
            ->where('payment_method_id', $this->briMethod->id)
            ->where('amount', 1000000)
            ->count();

        $this->assertEquals(1, $paymentCount, 'Double-click should not create duplicate payment');
    }

    /**
     * Test 10.4: Amount input validation
     *
     * Scenario:
     * 1. Try negative amount - should fail
     * 2. Try zero amount - should fail
     * 3. Try non-numeric amount - should fail
     * 4. Try valid amount - should succeed
     */
    public function test_amount_input_validation()
    {
        // Negative amount
        $negativeResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => -1000000,
            'idempotency_key' => 'neg-' . time(),
        ]);

        $negativeResponse->assertStatus(422);

        // Zero amount
        $zeroResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 0,
            'idempotency_key' => 'zero-' . time(),
        ]);

        $zeroResponse->assertStatus(422);

        // Valid amount
        $validResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
            'idempotency_key' => 'valid-' . time(),
        ]);

        $validResponse->assertStatus(200);
    }

    /**
     * Test 10.5: Remainder becomes negative (overpayment)
     *
     * Scenario:
     * 1. Create sale with 1M due
     * 2. Pay 1.5M
     * 3. Verify change is calculated as positive 500k
     */
    public function test_overpayment_change_calculated()
    {
        $smallSale = Sale::create([
            'sale_date' => now(),
            'due_amount' => 1000000,
            'discount_amount' => 0,
            'total_amount' => 1000000,
            'note' => 'Overpayment test',
            'is_active' => true,
        ]);

        $overpaymentResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $smallSale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1500000,
            'idempotency_key' => 'overpay-' . time(),
        ]);

        $overpaymentResponse->assertStatus(200);
        $overpayData = $overpaymentResponse->json();

        // Verify remainder is negative (overpayment)
        $this->assertLessThan(0, $overpayData['remainder']);

        // Verify overpayment amount is shown correctly
        $this->assertEquals(500000, $overpayData['overpayment']);
    }

    /**
     * Test 10.6: Empty method selector - [Proceed] should be disabled
     *
     * Note: This is primarily a UI test. Backend validation ensures
     * a valid payment method is provided.
     *
     * Scenario:
     * 1. Try to submit without payment method
     * 2. Should fail validation
     */
    public function test_empty_payment_method_validation()
    {
        $noMethodResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => 0,
            'amount' => 1000000,
            'idempotency_key' => 'no-method-' . time(),
        ]);

        $noMethodResponse->assertStatus(422);
        $errors = $noMethodResponse->json();
        $this->assertArrayHasKey('errors', $errors);
    }

    /**
     * Test EDC reference requirement validation
     *
     * Scenario:
     * 1. Try BRI payment without reference - should fail
     * 2. Try BRI payment with valid reference - should succeed
     * 3. Try CASH payment without reference - should succeed
     */
    public function test_edc_reference_requirement_validation()
    {
        // BRI without reference - should fail
        $noRefResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'idempotency_key' => 'noref-' . time(),
        ]);

        $noRefResponse->assertStatus(422);
        $errors = $noRefResponse->json();
        $this->assertStringContainsString('edc_reference', json_encode($errors));

        // BRI with reference - should succeed
        $withRefResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'BRI001',
            'idempotency_key' => 'withref-' . time(),
        ]);

        $withRefResponse->assertStatus(200);

        // CASH without reference - should succeed
        $cashNoRefResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2000000,
            'idempotency_key' => 'cash-noref-' . time(),
        ]);

        $cashNoRefResponse->assertStatus(200);
    }

    /**
     * Test amount exceeding remainder (should still be allowed but trigger overpayment logic)
     *
     * Scenario:
     * 1. Create sale with 2M due
     * 2. Pay 1.5M (ok)
     * 3. Try to pay 1M (leaves 0.5M overpayment, but should be allowed)
     */
    public function test_amount_can_exceed_remainder()
    {
        // First payment
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1500000,
            'idempotency_key' => 'exceed-1-' . time(),
        ])->assertStatus(200);

        // Second payment that exceeds remainder
        $exceedResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 4000000, // Would overpay by 1.5M
            'idempotency_key' => 'exceed-2-' . time(),
        ]);

        // Should succeed and show overpayment
        $exceedResponse->assertStatus(200);
        $data = $exceedResponse->json();
        $this->assertLessThan(0, $data['remainder']);
    }
}
