<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Pos\Entities\PaymentMethod;

/**
 * Task 5.5: Test reload recovery at various stages
 *
 * Verify that the payment chain persists in session and modal reopens
 * correctly after reload at different transaction stages.
 */
class PosMultiStagedPaymentReloadRecoveryTest extends TestCase
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
            'due_amount' => 3000000, // 3 million
            'discount_amount' => 0,
            'total_amount' => 3000000,
            'note' => 'Test multi-stage payment reload recovery',
            'is_active' => true,
        ]);
    }

    /**
     * Test reload after first payment commit
     *
     * Scenario:
     * 1. User submits first payment (BRI 1M)
     * 2. Page reloads during wait for next payment
     * 3. Modal should reopen with correct remainder (2M) and payment chain visible
     */
    public function test_reload_recovery_after_first_payment_commit()
    {
        // Step 1: Submit first payment via stage-payment endpoint
        $firstPaymentResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'TEST001',
            'idempotency_key' => 'idempotency-1-' . time(),
        ]);

        $firstPaymentResponse->assertStatus(200);
        $firstPaymentData = $firstPaymentResponse->json();

        // Verify first payment was committed
        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'TEST001',
        ]);

        // Verify remainder is correct
        $this->assertEquals(2000000, $firstPaymentData['remainder']);

        // Step 2: Simulate reload - call the recovery endpoint
        $recoveryResponse = $this->get("/api/pos/sell/checkout/payment-chain", [
            'sale_id' => $this->sale->id,
        ]);

        $recoveryResponse->assertStatus(200);
        $recoveryData = $recoveryResponse->json();

        // Verify payment chain is recovered
        $this->assertTrue($recoveryData['has_chain']);
        $this->assertEquals(2000000, $recoveryData['remainder']);
        $this->assertEquals(1000000, $recoveryData['total_committed']);
        $this->assertCount(1, $recoveryData['payment_chain']);
        $this->assertEquals('BRI', $recoveryData['payment_chain'][0]['method_name']);
        $this->assertEquals(1000000, $recoveryData['payment_chain'][0]['amount']);
        $this->assertEquals('TEST001', $recoveryData['payment_chain'][0]['edc_reference']);
    }

    /**
     * Test reload after second payment commit
     *
     * Scenario:
     * 1. User submits first payment (BRI 1M)
     * 2. User submits second payment (BNI 1M)
     * 3. Page reloads
     * 4. Modal should reopen with remainder (1M) and both payments visible
     */
    public function test_reload_recovery_after_second_payment_commit()
    {
        // First payment
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'TEST001',
            'idempotency_key' => 'idempotency-1-' . time(),
        ]);

        // Second payment
        $secondPaymentResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->bniMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'TEST002',
            'idempotency_key' => 'idempotency-2-' . time(),
        ]);

        $secondPaymentResponse->assertStatus(200);
        $secondPaymentData = $secondPaymentResponse->json();

        // Verify remainder is correct after 2 payments
        $this->assertEquals(1000000, $secondPaymentData['data']['remainder']);

        // Simulate reload - call recovery endpoint
        $recoveryResponse = $this->get("/api/pos/sell/checkout/payment-chain", [
            'sale_id' => $this->sale->id,
        ]);

        $recoveryResponse->assertStatus(200);
        $recoveryData = $recoveryResponse->json();

        // Verify full payment chain is recovered
        $this->assertTrue($recoveryData['has_chain']);
        $this->assertEquals(1000000, $recoveryData['remainder']);
        $this->assertEquals(2000000, $recoveryData['total_committed']);
        $this->assertCount(2, $recoveryData['payment_chain']);

        // Verify payment order
        $this->assertEquals('BRI', $recoveryData['payment_chain'][0]['method_name']);
        $this->assertEquals('BNI', $recoveryData['payment_chain'][1]['method_name']);
    }

    /**
     * Test reload after third payment commit
     *
     * Scenario:
     * 1. Three payments in sequence (BRI 1M, BNI 1M, CASH 1M)
     * 2. Reload happens after all three are committed
     * 3. Modal should show complete payment chain with remainder = 0
     */
    public function test_reload_recovery_after_third_payment_commit()
    {
        // First payment
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'TEST001',
            'idempotency_key' => 'idempotency-1-' . time(),
        ]);

        // Second payment
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->bniMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'TEST002',
            'idempotency_key' => 'idempotency-2-' . time(),
        ]);

        // Third payment
        $thirdPaymentResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000000,
            'idempotency_key' => 'idempotency-3-' . time(),
        ]);

        $thirdPaymentResponse->assertStatus(200);
        $thirdPaymentData = $thirdPaymentResponse->json();

        // Verify remainder is zero
        $this->assertEquals(0, $thirdPaymentData['data']['remainder']);

        // Simulate reload
        $recoveryResponse = $this->get("/api/pos/sell/checkout/payment-chain", [
            'sale_id' => $this->sale->id,
        ]);

        $recoveryResponse->assertStatus(200);
        $recoveryData = $recoveryResponse->json();

        // Verify all three payments visible
        $this->assertTrue($recoveryData['has_chain']);
        $this->assertEquals(0, $recoveryData['remainder']);
        $this->assertEquals(3000000, $recoveryData['total_committed']);
        $this->assertCount(3, $recoveryData['payment_chain']);

        // Verify all methods are present
        $methods = array_map(fn($p) => $p['method_name'], $recoveryData['payment_chain']);
        $this->assertEquals(['BRI', 'BNI', 'CASH'], $methods);
    }

    /**
     * Test session cleanup after finalization
     *
     * Scenario:
     * 1. Complete 2-stage payment
     * 2. Call finalize endpoint
     * 3. Verify session payment chain is cleared
     * 4. New reload should NOT recover old chain
     */
    public function test_session_cleared_after_finalize()
    {
        // Two stage payments
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1500000,
            'edc_reference' => 'TEST001',
            'idempotency_key' => 'idempotency-1-' . time(),
        ]);

        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1500000,
            'idempotency_key' => 'idempotency-2-' . time(),
        ]);

        // Finalize transaction
        $finalizeResponse = $this->post("/pos/sell/checkout/finalize", [
            'sale_id' => $this->sale->id,
        ]);

        $finalizeResponse->assertStatus(200);

        // Try to recover - should NOT find chain since it was finalized
        $recoveryResponse = $this->get("/api/pos/sell/checkout/payment-chain", [
            'sale_id' => $this->sale->id,
        ]);

        $recoveryResponse->assertStatus(200);
        $recoveryData = $recoveryResponse->json();

        // Session should be cleared after finalize
        $this->assertFalse($recoveryData['has_chain']);
    }

    /**
     * Test reload with incomplete payment (remainder pending)
     *
     * Scenario:
     * 1. Submit first payment (1.5M out of 3M)
     * 2. Reload happens - user hasn't submitted second payment yet
     * 3. Modal should show first payment + remainder (1.5M) ready for next stage
     */
    public function test_reload_with_incomplete_payment_chain()
    {
        // First payment - less than total
        $firstResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1500000,
            'edc_reference' => 'TEST001',
            'idempotency_key' => 'idempotency-1-' . time(),
        ]);

        $firstResponse->assertStatus(200);
        $this->assertEquals(1500000, $firstResponse->json()['data']['remainder']);

        // Simulate reload without second payment
        $recoveryResponse = $this->get("/api/pos/sell/checkout/payment-chain", [
            'sale_id' => $this->sale->id,
        ]);

        $recoveryResponse->assertStatus(200);
        $recoveryData = $recoveryResponse->json();

        // Verify incomplete chain is recoverable
        $this->assertTrue($recoveryData['has_chain']);
        $this->assertEquals(1500000, $recoveryData['remainder']);
        $this->assertCount(1, $recoveryData['payment_chain']);
    }

    /**
     * Test idempotency key prevents duplicate submissions on retry
     *
     * Scenario:
     * 1. Submit first payment with idempotency key
     * 2. Simulate network timeout - user doesn't see response
     * 3. User retries with same idempotency key
     * 4. Server should return same response without double-charging
     */
    public function test_idempotency_key_prevents_duplicates()
    {
        $idempotencyKey = 'idempotency-retry-' . time();
        $paymentData = [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'TEST001',
            'idempotency_key' => $idempotencyKey,
        ];

        // First submission
        $firstResponse = $this->post("/api/pos/sell/checkout/stage-payment", $paymentData);
        $firstResponse->assertStatus(200);
        $firstData = $firstResponse->json();

        // Verify payment was committed
        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $this->sale->id,
            'amount' => 1000000,
        ]);

        // Retry with same idempotency key
        $retryResponse = $this->post("/api/pos/sell/checkout/stage-payment", $paymentData);
        $retryResponse->assertStatus(200);
        $retryData = $retryResponse->json();

        // Verify response is identical
        $this->assertEquals($firstData['data']['remainder'], $retryData['data']['remainder']);

        // Verify only one payment record was created
        $paymentCount = SalePayment::where('sale_id', $this->sale->id)
            ->where('amount', 1000000)
            ->count();

        $this->assertEquals(1, $paymentCount, 'Idempotency key should prevent duplicate payments');
    }

    /**
     * Test 9.1: Test reload after 1st payment commit
     *
     * Verify modal reopens with correct remainder
     */
    public function test_reload_after_first_payment_commit_recovery()
    {
        // Submit first payment
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'TEST001',
            'idempotency_key' => 'reload-9-1-' . time(),
        ])->assertStatus(200);

        // Simulate reload recovery
        $recoveryResponse = $this->get("/api/pos/sell/checkout/payment-chain", [
            'sale_id' => $this->sale->id,
        ]);

        $recoveryData = $recoveryResponse->json();
        $this->assertTrue($recoveryData['has_chain']);
        $this->assertEquals(2000000, $recoveryData['remainder']);
        $this->assertEquals(1, count($recoveryData['payment_chain']));
    }

    /**
     * Test 9.2: Test reload after 2nd payment commit
     *
     * Verify full payment chain visible
     */
    public function test_reload_after_second_payment_commit_recovery()
    {
        // Two payments
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'TEST001',
            'idempotency_key' => 'reload-9-2-a-' . time(),
        ]);

        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->bniMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'TEST002',
            'idempotency_key' => 'reload-9-2-b-' . time(),
        ]);

        // Reload recovery
        $recoveryResponse = $this->get("/api/pos/sell/checkout/payment-chain", [
            'sale_id' => $this->sale->id,
        ]);

        $recoveryData = $recoveryResponse->json();
        $this->assertTrue($recoveryData['has_chain']);
        $this->assertEquals(1000000, $recoveryData['remainder']);
        $this->assertEquals(2, count($recoveryData['payment_chain']));
    }

    /**
     * Test 9.3: Test reload after session expiry
     *
     * Verify warning message and no auto-open
     * Note: This test verifies behavior when no session data exists
     */
    public function test_reload_after_session_expiry_no_recovery()
    {
        // Try to recover without any payments made
        $recoveryResponse = $this->get("/api/pos/sell/checkout/payment-chain", [
            'sale_id' => $this->sale->id,
        ]);

        $recoveryData = $recoveryResponse->json();
        $this->assertFalse($recoveryData['has_chain']);
    }

    /**
     * Test 9.4: Test reload with incomplete payment (remainder pending)
     *
     * Verify user can continue to next stage
     */
    public function test_reload_with_incomplete_payment_can_continue()
    {
        // First payment - incomplete
        $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->briMethod->id,
            'amount' => 1000000,
            'edc_reference' => 'TEST001',
            'idempotency_key' => 'reload-9-4-a-' . time(),
        ]);

        // Simulate reload
        $recoveryResponse = $this->get("/api/pos/sell/checkout/payment-chain", [
            'sale_id' => $this->sale->id,
        ]);

        $recoveryData = $recoveryResponse->json();
        $this->assertTrue($recoveryData['has_chain']);
        $this->assertEquals(2000000, $recoveryData['remainder']);

        // Can continue to next stage
        $continueResponse = $this->post("/api/pos/sell/checkout/stage-payment", [
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->bniMethod->id,
            'amount' => 2000000,
            'edc_reference' => 'TEST002',
            'idempotency_key' => 'reload-9-4-b-' . time(),
        ]);

        $continueData = $continueResponse->json();
        $this->assertEquals(0, $continueData['remainder']);
        $this->assertEquals(2, count($continueData['payment_chain']));
    }
}
