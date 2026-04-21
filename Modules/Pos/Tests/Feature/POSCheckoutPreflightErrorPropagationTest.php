<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * @group pos-critical-path
 *
 * Task 3.1 & 3.2: Preflight error propagation and mismatch modal data resilience
 *
 * Extends the existing POSCheckoutPreflightTest to verify structured error propagation
 * for UI consumption, ensuring mismatch modal can render with resilient fallbacks.
 */
class POSCheckoutPreflightErrorPropagationTest extends POSCheckoutPreflightTest
{
    /**
     * Test 3.1: Verify stock mismatch includes requested_qty and allocated_qty for modal rendering
     *
     * When preflight fails with insufficient stock, the response must include
     * canonical quantity fields so frontend can compute shortage deterministically.
     */
    public function test_stock_mismatch_includes_canonical_quantity_fields(): void
    {
        $context = $this->createCheckoutContext('STOCK CANONICAL');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-QTY-CANONICAL', 100000, false, 10);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 10);

        // Reduce available stock to 3
        DB::table('product_stocks')
            ->where('product_id', $product->id)
            ->where('location_id', $context['location']->id)
            ->update(['quantity' => 3, 'quantity_non_tax' => 3]);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.preflight'))
            ->assertStatus(422)
            ->assertJsonPath('details.unfulfilled_lines.0.requested_qty', 10)
            ->assertJsonPath('details.unfulfilled_lines.0.allocated_qty', 3)
            ->assertJsonPath('details.unfulfilled_lines.0.product_id', $product->id);
    }

    /**
     * Test 3.2: Verify product identifier fallback is available (product_code or product_id)
     *
     * Frontend renders mismatch modal with priority: product_name → product_code → Product #id.
     * This test ensures the response always includes enough identifier information.
     */
    public function test_mismatch_line_includes_product_identifiers(): void
    {
        $context = $this->createCheckoutContext('PRODUCT ID FALLBACK');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-ID-FB', 100000, false, 5);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 5);

        // Reduce stock to trigger failure
        DB::table('product_stocks')
            ->where('product_id', $product->id)
            ->where('location_id', $context['location']->id)
            ->update(['quantity' => 1, 'quantity_non_tax' => 1]);

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.preflight'));

        $response->assertStatus(422);
        $line = $response->json('details.unfulfilled_lines.0');

        // Verify at least one identifier exists for fallback chain
        $this->assertTrue(
            isset($line['product_name']) || isset($line['product_code']) || isset($line['product_id']),
            'Must have product_name, product_code, or product_id for label rendering'
        );
    }

    /**
     * Test 3.3: Verify serial mismatch returns structured invalid_lines detail
     *
     * When serial validation fails, error details include invalid_lines array
     * for UI to render specific serial error message per line.
     */
    public function test_serial_mismatch_returns_invalid_lines_detail(): void
    {
        $context = $this->createCheckoutContext('SERIAL DETAIL');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-SER-DETAIL', 100000, true);

        // Add serial product without assigning serial numbers
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.preflight'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SERIAL_INVALID')
            ->assertJsonPath('details.invalid_lines', function ($lines) {
                return is_array($lines) && count($lines) > 0;
            });
    }

    /**
     * Test 3.4: Success path doesn't include error details
     *
     * When preflight passes, response should be clean with status=OK and no details,
     * ensuring frontend logic doesn't trigger mismatch modal on success.
     */
    public function test_preflight_success_clean_response_structure(): void
    {
        $context = $this->createCheckoutContext('SUCCESS CLEAN');
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-CLEAN', 100000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.preflight'));

        $response->assertOk()
            ->assertJsonPath('status', 'OK')
            ->assertJsonMissing(['details']);
    }
}
