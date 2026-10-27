<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\PaymentMethod;

/**
 * @group pos-payment-chain-recovery
 *
 * Tests for payment chain persistence and recovery across HTTP requests.
 * Uses the actual stagePayment, getPaymentChain, and resetPaymentChain endpoints
 * to verify the cart-token contract is correctly implemented.
 */
class PosPaymentChainRecoveryTest extends PosTransactionFeatureTestCase
{
    use RefreshDatabase;

    private $setting;
    private $cashier;
    private $terminal;
    private $session;
    private $paymentMethod;
    private $paymentTerm;
    private $grandTotal = 100000.00;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->setting = $this->createSetting('Recovery Test Company');
        $this->cashier = $this->createUserForSetting($this->setting, 'Cashier Recovery', [
            'pos.access',
            'pos.sell',
            'pos.checkout.payment',
        ]);

        [$this->terminal, $location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->cashier);

        $coaId = \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'CASH RECOVERY TEST COA',
            'account_number' => 'CASH-RECOVERY-001',
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'name' => 'CASH RECOVERY TEST',
            'coa_id' => $coaId,
            'is_cash' => true,
            'is_active' => true,
        ]);

        $this->nonCashPaymentMethod = PaymentMethod::create([
            'name' => 'TRANSFER RECOVERY TEST',
            'coa_id' => $coaId,
            'is_cash' => false,
            'is_active' => true,
        ]);

        $this->paymentTerm = PaymentTerm::create([
            'name' => 'Net 30 Recovery',
            'longevity' => 30,
        ]);

        $this->actingAsInSetting($this->cashier, $this->setting);
    }

    public function test_committed_payment_chain_persists_across_requests(): void
    {
        // Setup: Create a cart token (uuid)
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        // Action 1: Stage first non-cash payment via POST (60k of 100k) in full_payment flow
        $stageResponse = $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $cartToken,
            'payment_method_id' => $this->nonCashPaymentMethod->id,
            'amount' => 60000,
            'grand_total' => $this->grandTotal,
            'flow' => 'full_payment',
        ]);

        $stageResponse->assertStatus(201);
        $stagedChain = $stageResponse->json('payment_chain');
        $this->assertCount(1, $stagedChain['payments']);
        $this->assertEquals(40000, $stagedChain['remainder']);
        $this->assertEquals('full_payment', $stagedChain['flow']);

        // Action 2: Retrieve chain via GET (simulating recovery/reload)
        $getResponse = $this->getJson('/pos/sell/checkout/payment-chain?cart_token=' . $cartToken);

        $getResponse->assertStatus(200);
        $getResponse->assertJsonPath('has_chain', true);
        $retrievedChain = $getResponse->json('payment_chain');

        // Verify: Chain persists with correct data
        $this->assertCount(1, $retrievedChain['payments']);
        $this->assertEquals(60000, $retrievedChain['payments'][0]['amount']);
        $this->assertEquals(40000, $retrievedChain['remainder']);
        $this->assertEquals('full_payment', $retrievedChain['flow']);

        // Action 3: Stage final cash payment to the same chain (40k to complete)
        $stage2Response = $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $cartToken,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 40000,
            'grand_total' => $this->grandTotal,
            'flow' => 'full_payment',
        ]);

        $stage2Response->assertStatus(201);
        $chainAfterStage2 = $stage2Response->json('payment_chain');
        $this->assertCount(2, $chainAfterStage2['payments']);
        $this->assertEquals(0, $chainAfterStage2['remainder']);

        // Action 4: Verify both payments persist in recovery GET
        $getFinalResponse = $this->getJson('/pos/sell/checkout/payment-chain?cart_token=' . $cartToken);

        $getFinalResponse->assertStatus(200);
        $getFinalResponse->assertJsonPath('has_chain', true);
        $finalChain = $getFinalResponse->json('payment_chain');
        $this->assertCount(2, $finalChain['payments']);
        $totalAmount = array_sum(array_column($finalChain['payments'], 'amount'));
        $this->assertEquals(100000, $totalAmount);
    }

    public function test_kasbon_multiple_down_payments_persist_across_requests(): void
    {
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        // Stage 1: First Kasbon down payment (30k of 100k)
        $stage1 = $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $cartToken,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 30000,
            'grand_total' => $this->grandTotal,
            'flow' => 'kasbon',
            'is_debt' => true,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $stage1->assertStatus(201);
        $this->assertEquals(70000, $stage1->json('payment_chain.remainder'));
        $this->assertEquals('kasbon', $stage1->json('payment_chain.flow'));

        // Stage 2: Second Kasbon down payment (20k)
        $stage2 = $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $cartToken,
            'payment_method_id' => $this->nonCashPaymentMethod->id,
            'amount' => 20000,
            'grand_total' => $this->grandTotal,
            'flow' => 'kasbon',
            'is_debt' => true,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $stage2->assertStatus(201);
        $this->assertEquals(50000, $stage2->json('payment_chain.remainder'));

        // Retrieve chain via GET
        $getResponse = $this->getJson('/pos/sell/checkout/payment-chain?cart_token=' . $cartToken);
        $getResponse->assertStatus(200);
        $chain = $getResponse->json('payment_chain');
        $this->assertCount(2, $chain['payments']);
        $this->assertEquals(50000, $chain['remainder']);
        $this->assertEquals('kasbon', $chain['flow']);
        $this->assertTrue($chain['is_debt']);
    }

    public function test_explicit_flow_persists_and_legacy_is_debt_infers_flow(): void
    {
        // 1. Explicit full_payment flow
        $token1 = \Illuminate\Support\Str::uuid()->toString();
        $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $token1,
            'payment_method_id' => $this->nonCashPaymentMethod->id,
            'amount' => 25000,
            'grand_total' => $this->grandTotal,
            'flow' => 'full_payment',
        ])->assertStatus(201);

        $res1 = $this->getJson('/pos/sell/checkout/payment-chain?cart_token=' . $token1);
        $res1->assertStatus(200);
        $this->assertEquals('full_payment', $res1->json('payment_chain.flow'));

        // 2. Explicit kasbon flow
        $token2 = \Illuminate\Support\Str::uuid()->toString();
        $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $token2,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 25000,
            'grand_total' => $this->grandTotal,
            'flow' => 'kasbon',
            'is_debt' => true,
            'payment_term_id' => $this->paymentTerm->id,
        ])->assertStatus(201);

        $res2 = $this->getJson('/pos/sell/checkout/payment-chain?cart_token=' . $token2);
        $res2->assertStatus(200);
        $this->assertEquals('kasbon', $res2->json('payment_chain.flow'));

        // 3. Legacy session chain with is_debt = true (no flow key)
        $token3 = \Illuminate\Support\Str::uuid()->toString();
        session()->put("payment_chain_{$token3}", [
            'payments' => [],
            'remainder' => 100000,
            'staged_at' => now()->toIso8601String(),
            'is_debt' => true,
        ]);

        $res3 = $this->getJson('/pos/sell/checkout/payment-chain?cart_token=' . $token3);
        $res3->assertStatus(200);
        $this->assertEquals('kasbon', $res3->json('payment_chain.flow'));

        // 4. Legacy session chain with is_debt = false (no flow key)
        $token4 = \Illuminate\Support\Str::uuid()->toString();
        session()->put("payment_chain_{$token4}", [
            'payments' => [],
            'remainder' => 100000,
            'staged_at' => now()->toIso8601String(),
            'is_debt' => false,
        ]);

        $res4 = $this->getJson('/pos/sell/checkout/payment-chain?cart_token=' . $token4);
        $res4->assertStatus(200);
        $this->assertEquals('full_payment', $res4->json('payment_chain.flow'));
    }

    public function test_explicit_payment_chain_reset_removes_committed_stages(): void
    {
        // Setup: Create a cart token and stage a payment
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        $stageResponse = $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $cartToken,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 60000,
            'grand_total' => $this->grandTotal,
            'flow' => 'kasbon',
            'is_debt' => true,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $stageResponse->assertStatus(201);

        // Verify: Chain exists before reset
        $getBeforeResponse = $this->getJson('/pos/sell/checkout/payment-chain?cart_token=' . $cartToken);

        $getBeforeResponse->assertStatus(200);
        $getBeforeResponse->assertJsonPath('has_chain', true);
        $chainBefore = $getBeforeResponse->json('payment_chain');
        $this->assertCount(1, $chainBefore['payments']);
        $this->assertEquals(60000, $chainBefore['payments'][0]['amount']);

        // Action: Explicitly reset the payment chain via DELETE
        $deleteResponse = $this->deleteJson('/pos/sell/checkout/payment-chain', [
            'cart_token' => $cartToken,
        ]);

        $deleteResponse->assertStatus(200);

        // Verify: Chain is gone after reset
        $getAfterResponse = $this->getJson('/pos/sell/checkout/payment-chain?cart_token=' . $cartToken);

        $getAfterResponse->assertStatus(200);
        $getAfterResponse->assertJsonPath('has_chain', false);
        $getAfterResponse->assertJsonPath('payment_chain', null);
    }

    public function test_reset_payment_chain_under_lock_preserves_cart_customer_and_notes(): void
    {
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        // 1. Put cart into PosCartSessionStore with customer and note
        /** @var \Modules\Pos\Services\PosCartSessionStore $cartStore */
        $cartStore = app(\Modules\Pos\Services\PosCartSessionStore::class);
        $cartStore->putCart((int) $this->setting->id, (int) $this->session->id, [
            'lines' => [
                1 => ['line_id' => 1, 'product_id' => 999, 'quantity' => 2, 'unit_price' => 50000],
            ],
            'selected_customer_id' => 123,
            'note' => 'Preserve this note after reset',
        ]);

        // 2. Stage a payment into the chain
        $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $cartToken,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 40000,
            'grand_total' => $this->grandTotal,
            'flow' => 'kasbon',
            'is_debt' => true,
            'payment_term_id' => $this->paymentTerm->id,
        ])->assertStatus(201);

        // 3. Reset payment chain
        $deleteResponse = $this->deleteJson('/pos/sell/checkout/payment-chain', [
            'cart_token' => $cartToken,
        ]);
        $deleteResponse->assertStatus(200);

        // 4. Assert payment chain is cleared
        $getResponse = $this->getJson('/pos/sell/checkout/payment-chain?cart_token=' . $cartToken);
        $getResponse->assertJsonPath('has_chain', false);

        // 5. Assert cart data, customer, and notes in PosCartSessionStore are untouched
        $cart = $cartStore->getCart((int) $this->setting->id, (int) $this->session->id);
        $this->assertCount(1, $cart['lines']);
        $this->assertEquals(123, $cart['selected_customer_id']);
        $this->assertEquals('Preserve this note after reset', $cart['note']);
    }

    public function test_unauthorized_payment_chain_reset_is_rejected(): void
    {
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        // Create user without pos.checkout.payment
        $unauthorizedUser = $this->createUserForSetting($this->setting, 'Unauthorized Cashier', [
            'pos.access',
            'pos.sell',
        ]);

        [$terminal2] = $this->createTerminalWithLocation($this->setting);
        $this->openSession($this->setting, $terminal2, $unauthorizedUser);

        $this->actingAsInSetting($unauthorizedUser, $this->setting);

        $response = $this->deleteJson('/pos/sell/checkout/payment-chain', [
            'cart_token' => $cartToken,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'CHECKOUT_PERMISSION_REQUIRED');
    }

    public function test_reconcile_payment_chain_authoritatively_updates_server_session_and_accepts_new_remainder(): void
    {
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        // 1. Stage non-cash payment of 60,000 for a 100,000 cart
        $stage1 = $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $cartToken,
            'payment_method_id' => $this->nonCashPaymentMethod->id,
            'amount' => 60000,
            'grand_total' => 100000,
            'flow' => 'full_payment',
        ]);
        $stage1->assertStatus(201);
        $this->assertEquals(40000, $stage1->json('remainder'));

        // 2. Cart total increases to 150,000 on frontend -> Call reconcile-payment-chain
        $reconcile = $this->postJson('/pos/sell/checkout/reconcile-payment-chain', [
            'cart_token' => $cartToken,
            'grand_total' => 150000,
            'flow' => 'full_payment',
        ]);
        $reconcile->assertStatus(200);
        $this->assertEquals(150000, $reconcile->json('payment_chain.original_grand_total'));
        $this->assertEquals(90000, $reconcile->json('payment_chain.remainder'));

        // 3. Stage second non-cash payment of 90,000 for the updated 150,000 cart
        // Server must accept 90,000 without rejecting with AMOUNT_EXCEEDS_REMAINDER
        $stage2 = $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $cartToken,
            'payment_method_id' => $this->nonCashPaymentMethod->id,
            'amount' => 90000,
            'grand_total' => 150000,
            'flow' => 'full_payment',
        ]);
        $stage2->assertStatus(201);
        $this->assertEquals(0, $stage2->json('remainder'));
        $this->assertCount(2, $stage2->json('payment_chain.payments'));
    }

    public function test_reconcile_payment_chain_preserves_cash_overpayment_signed_remainder(): void
    {
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        // 1. Stage cash payment of 120,000 for a 100,000 cart (20,000 change)
        $stage = $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $cartToken,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 120000,
            'grand_total' => 100000,
            'flow' => 'full_payment',
        ]);
        $stage->assertStatus(201);
        $this->assertEquals(-20000, $stage->json('remainder'));

        // 2. Reduce cart to 90,000 in POS cart shell and call reconcile-payment-chain
        $reconcile = $this->postJson('/pos/sell/checkout/reconcile-payment-chain', [
            'cart_token' => $cartToken,
            'grand_total' => 90000,
            'flow' => 'full_payment',
        ]);
        $reconcile->assertStatus(200);

        // Verify signed remainder (-30,000) is preserved rather than clamped to 0.0
        $this->assertEquals(90000, $reconcile->json('payment_chain.original_grand_total'));
        $this->assertEquals(-30000, $reconcile->json('payment_chain.remainder'));
        $this->assertEquals(-30000, $reconcile->json('remainder'));
    }
}
