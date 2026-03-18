<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSessionCashEvent;

/**
 * Task 6.1-6.6: Feature tests for multi-payment finalize with mixed cash/non-cash tender
 *
 * @group pos-multi-payment
 */
class POSCheckoutMultiPaymentFinalizeTest extends POSCheckoutFinalizeIdempotencyTest
{
    // Inherits all test setup and helpers from POSCheckoutFinalizeIdempotencyTest

    /**
     * Task 6.1: Test successful multi-payment checkout with mixed cash and non-cash
     */
    public function test_multi_payment_finalize_with_mixed_cash_non_cash(): void
    {
        $context = $this->createCheckoutContext('POS MULTI-PAYMENT MIXED');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'MP-001', 100000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Task 6.1: Submit multi-payment payload with both cash and non-cash
        $payload = [
            'idempotency_key' => 'MP-MIXED-001',
            'payments' => [
                [
                    'payment_method_id' => $methods['cash']->id,
                    'amount_paid' => 60000,
                ],
                [
                    'payment_method_id' => $methods['qris']->id,
                    'amount_paid' => 40000,
                    'reference' => 'REF001',
                ],
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'POSTED')
            ->assertJsonPath('paid_total', 100000.0)
            ->assertJsonPath('change_total', 0.0);

        // Task 6.1: Verify payment entries were created
        $checkout = PosCheckout::where('idempotency_key', strtolower('MP-MIXED-001'))->first();
        $this->assertNotNull($checkout);
        $this->assertEquals(2, $checkout->payments()->count());

        // Verify cash payment entry
        $cashPayment = $checkout->payments()
            ->where('payment_method_id', $methods['cash']->id)
            ->first();
        $this->assertNotNull($cashPayment);
        $this->assertEquals(60000, $cashPayment->amount_paid);

        // Verify non-cash payment entry
        $cardPayment = $checkout->payments()
            ->where('payment_method_id', $methods['qris']->id)
            ->first();
        $this->assertNotNull($cardPayment);
        $this->assertEquals(40000, $cardPayment->amount_paid);
    }

    /**
     * Task 6.1: Test multi-payment with required reference validation
     */
    public function test_multi_payment_requires_reference_validation(): void
    {
        $context = $this->createCheckoutContext('POS MULTI-PAYMENT REF');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'MP-002', 50000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Task 6.1: Submit multi-payment with missing required reference
        $payload = [
            'idempotency_key' => 'MP-REF-001',
            'payments' => [
                [
                    'payment_method_id' => $methods['transfer']->id, // Requires reference
                    'amount_paid' => 50000,
                    'reference' => '', // Missing reference
                ],
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);

        // Should fail validation
        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * Task 6.1: Test multi-payment change calculation from multiple payment sources
     */
    public function test_multi_payment_change_calculation(): void
    {
        $context = $this->createCheckoutContext('POS MULTI-PAYMENT CHANGE');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'MP-003', 75000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Task 6.1: Overpayment from cash with non-cash partial payment
        $payload = [
            'idempotency_key' => 'MP-CHANGE-001',
            'payments' => [
                [
                    'payment_method_id' => $methods['cash']->id,
                    'amount_paid' => 50000,
                ],
                [
                    'payment_method_id' => $methods['qris']->id,
                    'amount_paid' => 30000,
                    'reference' => 'REF30000',
                ],
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);

        $response->assertStatus(201)
            ->assertJsonPath('paid_total', 80000.0)
            ->assertJsonPath('change_total', 5000.0); // 80000 - 75000
    }

    /**
     * Task 6.3: Test idempotency replay with multi-payment payloads
     */
    public function test_multi_payment_idempotency_replay_returns_identical_composition(): void
    {
        $context = $this->createCheckoutContext('POS MULTI-PAYMENT IDEMPOTENCY');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'MP-IDLE-001', 100000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'MP-IDLE-001',
            'payments' => [
                [
                    'payment_method_id' => $methods['cash']->id,
                    'amount_paid' => 60000,
                ],
                [
                    'payment_method_id' => $methods['qris']->id,
                    'amount_paid' => 40000,
                    'reference' => 'REF40000',
                ],
            ],
        ];

        // First request
        $first = $this->finalize($context['cashier'], $context['setting'], $payload);
        $first->assertStatus(201)
            ->assertJsonPath('status', 'POSTED')
            ->assertJsonPath('idempotent_replay', false);

        // Replay with same payload
        $second = $this->finalize($context['cashier'], $context['setting'], $payload);
        $second->assertStatus(200)
            ->assertJsonPath('status', 'POSTED')
            ->assertJsonPath('idempotent_replay', true);

        // Verify payment compositions are identical
        $firstPayment = $first->json();
        $secondPayment = $second->json();
        $this->assertEquals($firstPayment['paid_total'], $secondPayment['paid_total']);
        $this->assertEquals($firstPayment['change_total'], $secondPayment['change_total']);

        // Verify only one checkout record exists
        $this->assertDatabaseCount('pos_checkouts', 1);
        $this->assertDatabaseCount('pos_checkout_payments', 2);
    }

    /**
     * Task 6.5: Test that mixed-method checkout correctly computes cash event from cash component only
     */
    public function test_multi_payment_mixed_method_cash_event_uses_cash_component_only(): void
    {
        $context = $this->createCheckoutContext('POS MULTI-CASH EVENT');
        $methods = $context['methods'];
        $session = $context['session'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'CASH-EVENT-001', 100000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Mixed payment: 60000 cash + 40000 card
        $payload = [
            'idempotency_key' => 'CASH-EVENT-001',
            'payments' => [
                [
                    'payment_method_id' => $methods['cash']->id,
                    'amount_paid' => 60000,
                ],
                [
                    'payment_method_id' => $methods['qris']->id,
                    'amount_paid' => 40000,
                    'reference' => 'REF40000',
                ],
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);
        $response->assertStatus(201);

        $checkoutId = (int) $response->json('pos_checkout_id');

        // Verify cash event was created with only the cash component amount (60000), not grand total (100000)
        $this->assertDatabaseHas('pos_session_cash_events', [
            'setting_id' => $context['setting']->id,
            'pos_session_id' => $session->id,
            'event_type' => PosSessionCashEvent::EVENT_CASH_SALE_IN,
            'direction' => PosSessionCashEvent::DIRECTION_IN,
            'amount' => 60000, // Only cash component, not 100000
            'reference_id' => $checkoutId,
        ]);

        // Verify expected cash was updated by cash component only
        $expectedCash = (float) DB::table('pos_sessions')->where('id', $session->id)->value('expected_cash_total');
        $this->assertSame(160000.0, $expectedCash); // 100000 initial + 60000 cash
    }

    /**
     * Task 6.5: Test that non-cash payment does not create cash event
     */
    public function test_multi_payment_non_cash_method_creates_no_cash_event(): void
    {
        $context = $this->createCheckoutContext('POS NON-CASH EVENT');
        $methods = $context['methods'];
        $session = $context['session'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'NON-CASH-001', 50000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Pure non-cash payment (no cash component)
        $payload = [
            'idempotency_key' => 'NON-CASH-001',
            'payments' => [
                [
                    'payment_method_id' => $methods['qris']->id,
                    'amount_paid' => 50000,
                    'reference' => 'REF50000',
                ],
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);
        $response->assertStatus(201);

        // Verify no cash event was created for non-cash payment
        $this->assertDatabaseMissing('pos_session_cash_events', [
            'setting_id' => $context['setting']->id,
            'pos_session_id' => $session->id,
            'event_type' => PosSessionCashEvent::EVENT_CASH_SALE_IN,
            'reference_id' => (int) $response->json('pos_checkout_id'),
        ]);

        // Verify expected cash unchanged
        $expectedCash = (float) DB::table('pos_sessions')->where('id', $session->id)->value('expected_cash_total');
        $this->assertSame(100000.0, $expectedCash); // No change
    }

    /**
     * Task 6.5: Test mixed-method overpayment cash change calculation
     */
    public function test_multi_payment_mixed_method_with_cash_overpay_computes_change_correctly(): void
    {
        $context = $this->createCheckoutContext('POS MULTI-OVERPAY');
        $methods = $context['methods'];
        $session = $context['session'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'OVERPAY-001', 75000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Mixed payment with cash overpayment
        $payload = [
            'idempotency_key' => 'OVERPAY-001',
            'payments' => [
                [
                    'payment_method_id' => $methods['cash']->id,
                    'amount_paid' => 50000,
                ],
                [
                    'payment_method_id' => $methods['qris']->id,
                    'amount_paid' => 30000,
                    'reference' => 'REF30000',
                ],
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);
        $response->assertStatus(201)
            ->assertJsonPath('paid_total', 80000.0)
            ->assertJsonPath('change_total', 5000.0); // 80000 - 75000

        $checkoutId = (int) $response->json('pos_checkout_id');

        // Verify cash event uses only cash component for expected-cash calculation
        $this->assertDatabaseHas('pos_session_cash_events', [
            'setting_id' => $context['setting']->id,
            'pos_session_id' => $session->id,
            'event_type' => PosSessionCashEvent::EVENT_CASH_SALE_IN,
            'amount' => 50000, // Cash component, not 80000 paid_total
            'reference_id' => $checkoutId,
        ]);
    }

    /**
     * Task 6.4: Test that legacy single-payment compatibility still works
     */
    public function test_legacy_single_payment_field_still_works(): void
    {
        $context = $this->createCheckoutContext('POS LEGACY PAYMENT');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'LEGACY-001', 50000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Legacy payload using single 'payment' field
        $payload = [
            'idempotency_key' => 'LEGACY-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 50000,
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);

        // Should still work for backward compatibility
        $response->assertStatus(201)
            ->assertJsonPath('status', 'POSTED')
            ->assertJsonPath('paid_total', 50000.0)
            ->assertJsonPath('change_total', 0.0)
            ->assertJsonPath('sale_id', true) // Top-level compatibility field
            ->assertJsonPath('sale_payment_id', true)
            ->assertJsonPath('dispatch_ids', true);
    }

    /**
     * Task 6.4: Test that multi-payment response includes legacy compatibility fields
     */
    public function test_multi_payment_includes_legacy_compatibility_fields(): void
    {
        $context = $this->createCheckoutContext('POS MULTI-COMPAT');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'COMPAT-001', 100000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Multi-payment payload
        $payload = [
            'idempotency_key' => 'COMPAT-001',
            'payments' => [
                [
                    'payment_method_id' => $methods['cash']->id,
                    'amount_paid' => 60000,
                ],
                [
                    'payment_method_id' => $methods['qris']->id,
                    'amount_paid' => 40000,
                    'reference' => 'REF40000',
                ],
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);

        // Multi-payment should still include top-level compatibility fields
        $response->assertStatus(201)
            ->assertJsonPath('status', 'POSTED')
            ->assertJsonPath('sale_id', true) // Mapped from deterministic first split group
            ->assertJsonPath('sale_payment_id', true)
            ->assertJsonPath('dispatch_ids', true)
            ->assertJsonPath('paid_total', 100000.0)
            ->assertJsonPath('change_total', 0.0);

        // Verify structure includes both legacy and new fields
        $data = $response->json();
        $this->assertIsInt($data['sale_id']);
        $this->assertIsInt($data['sale_payment_id']);
        $this->assertIsArray($data['dispatch_ids']);
    }

    /**
     * Task 6.3: Test mismatch detection for altered payment composition
     */
    public function test_multi_payment_altered_composition_returns_conflict(): void
    {
        $context = $this->createCheckoutContext('POS MULTI-PAYMENT MISMATCH');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'MP-MISMATCH-001', 100000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $firstPayload = [
            'idempotency_key' => 'MP-MISMATCH-001',
            'payments' => [
                [
                    'payment_method_id' => $methods['cash']->id,
                    'amount_paid' => 60000,
                ],
                [
                    'payment_method_id' => $methods['qris']->id,
                    'amount_paid' => 40000,
                    'reference' => 'REF40000',
                ],
            ],
        ];

        // First request succeeds
        $first = $this->finalize($context['cashier'], $context['setting'], $firstPayload);
        $first->assertStatus(201);

        // Attempt replay with different payment composition
        $secondPayload = [
            'idempotency_key' => 'MP-MISMATCH-001',
            'payments' => [
                [
                    'payment_method_id' => $methods['cash']->id,
                    'amount_paid' => 50000, // Changed amount
                ],
                [
                    'payment_method_id' => $methods['qris']->id,
                    'amount_paid' => 50000,
                    'reference' => 'REF50000',
                ],
            ],
        ];

        // Second request with altered composition should return conflict
        $second = $this->finalize($context['cashier'], $context['setting'], $secondPayload);
        $second->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_MISMATCH');
    }

    /**
     * Task 6.3: Test multi-payment idempotency with different payment method combination
     */
    public function test_multi_payment_altered_methods_returns_conflict(): void
    {
        $context = $this->createCheckoutContext('POS MULTI-PAYMENT METHOD-MISMATCH');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'MP-METHODS-001', 100000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $firstPayload = [
            'idempotency_key' => 'MP-METHODS-001',
            'payments' => [
                [
                    'payment_method_id' => $methods['cash']->id,
                    'amount_paid' => 100000,
                ],
            ],
        ];

        // First request succeeds
        $first = $this->finalize($context['cashier'], $context['setting'], $firstPayload);
        $first->assertStatus(201);

        // Attempt replay with different payment methods
        $secondPayload = [
            'idempotency_key' => 'MP-METHODS-001',
            'payments' => [
                [
                    'payment_method_id' => $methods['qris']->id,
                    'amount_paid' => 100000,
                    'reference' => 'REF100000',
                ],
            ],
        ];

        // Second request with altered methods should return conflict
        $second = $this->finalize($context['cashier'], $context['setting'], $secondPayload);
        $second->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_MISMATCH');
    }
}
