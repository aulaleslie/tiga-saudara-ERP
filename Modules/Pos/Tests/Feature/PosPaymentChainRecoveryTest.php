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

        // Action 1: Stage first cash payment via POST (60k of 100k) - using debt to allow split payment
        $stageResponse = $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $cartToken,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 60000,
            'grand_total' => $this->grandTotal,
            'is_debt' => true,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $stageResponse->assertStatus(201);
        $stagedChain = $stageResponse->json('payment_chain');
        $this->assertCount(1, $stagedChain['payments']);
        $this->assertEquals(40000, $stagedChain['remainder']);

        // Action 2: Retrieve chain via GET (simulating recovery/reload)
        $getResponse = $this->getJson('/pos/sell/checkout/payment-chain?cart_token=' . $cartToken);

        $getResponse->assertStatus(200);
        $getResponse->assertJsonPath('has_chain', true);
        $retrievedChain = $getResponse->json('payment_chain');

        // Verify: Chain persists with correct data
        $this->assertCount(1, $retrievedChain['payments']);
        $this->assertEquals(60000, $retrievedChain['payments'][0]['amount']);
        $this->assertEquals(40000, $retrievedChain['remainder']);

        // Action 3: Stage final payment to the same chain (40k to complete)
        $stage2Response = $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $cartToken,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 40000,
            'grand_total' => $this->grandTotal,
            'is_debt' => true,
            'payment_term_id' => $this->paymentTerm->id,
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

    public function test_explicit_payment_chain_reset_removes_committed_stages(): void
    {
        // Setup: Create a cart token and stage a payment
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        $stageResponse = $this->postJson('/pos/sell/checkout/stage-payment', [
            'cart_token' => $cartToken,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 60000,
            'grand_total' => $this->grandTotal,
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

}
