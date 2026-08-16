<?php

namespace Modules\Pos\Tests\Unit;

use DomainException;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Services\PosRowOverrideApprovalPayloadBuilder;
use Tests\TestCase;

/**
 * Approval payloads are built server-side, in minor units, and compare like
 * with like. The discounted-row case is the important one: deriving the source
 * total as `qty x unit_price` ignores the discount and reports a delta against
 * a total the cashier never saw.
 */
class PosRowOverrideApprovalPayloadBuilderTest extends TestCase
{
    private PosRowOverrideApprovalPayloadBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = app(PosRowOverrideApprovalPayloadBuilder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function cart(array $lineOverrides = [], array $cartOverrides = []): array
    {
        $line = array_merge([
            'line_id' => 1,
            'product_id' => 42,
            'product_name' => 'PRODUK UJI',
            'qty' => 2,
            'unit_price' => 10000.0,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0.0,
            'tax_id' => null,
            'tax_rate' => 0.0,
            'price_source' => 'BASE',
            'assigned_serials' => [],
            'bundle_items' => [],
        ], $lineOverrides);

        return array_merge([
            'setting_id' => 1,
            'session_id' => 1,
            'lines' => [1 => $line],
            'bill_discount_type' => 'fixed',
            'bill_discount_value' => 0.0,
            'selected_customer_id' => null,
            'selected_customer_tier' => null,
        ], $cartOverrides);
    }

    private function build(string $action, int $requestedMinor, array $lineOverrides = [], array $cartOverrides = []): array
    {
        $cart = $this->cart($lineOverrides, $cartOverrides);

        return $this->builder->build(
            $action,
            settingId: 1,
            posSessionId: 3,
            lineId: 1,
            cart: $cart,
            line: $cart['lines'][1],
            requestedValueMinor: $requestedMinor,
            requesterId: 11,
            reason: 'Koreksi kasir'
        );
    }

    public function test_unit_price_payload_compares_unit_prices(): void
    {
        $payload = $this->build(
            PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE,
            900_000 // Rp9.000
        );

        $this->assertSame('UNIT_PRICE', $payload['value_kind']);
        $this->assertSame(1_000_000, $payload['source_value_minor'], 'Source must be the unit price.');
        $this->assertSame(900_000, $payload['requested_value_minor']);
        $this->assertSame(-100_000, $payload['delta_minor']);
        $this->assertSame(900_000, $payload['requested_unit_price_minor']);
        $this->assertNull($payload['requested_total_minor']);
    }

    public function test_row_total_payload_compares_row_totals(): void
    {
        // qty 2 x Rp10.000 = Rp20.000 current row total.
        $payload = $this->build(
            PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
            1_800_000 // Rp18.000
        );

        $this->assertSame('ROW_TOTAL', $payload['value_kind']);
        $this->assertSame(2_000_000, $payload['source_value_minor']);
        $this->assertSame(1_800_000, $payload['requested_value_minor']);
        $this->assertSame(-200_000, $payload['delta_minor']);
        $this->assertSame(1_800_000, $payload['requested_total_minor']);
        $this->assertNull($payload['requested_unit_price_minor']);
    }

    public function test_discounted_row_reports_the_post_discount_source_total(): void
    {
        // qty 2 x Rp10.000 = Rp20.000 gross, less a Rp5.000 fixed row discount
        // => the authoritative current total is Rp15.000, not Rp20.000.
        $payload = $this->build(
            PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
            1_400_000,
            ['line_discount_type' => 'fixed', 'line_discount_value' => 5000.0]
        );

        $this->assertSame(
            1_500_000,
            $payload['source_value_minor'],
            'Source total ignored the row discount (qty x unit_price defect).'
        );
        $this->assertNotSame(2_000_000, $payload['source_value_minor']);
        $this->assertSame(-100_000, $payload['delta_minor']);
    }

    public function test_percentage_discounted_row_reports_the_post_discount_total(): void
    {
        // Rp20.000 gross less 10% => Rp18.000 authoritative current total.
        $payload = $this->build(
            PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
            1_700_000,
            ['line_discount_type' => 'percentage', 'line_discount_value' => 10.0]
        );

        $this->assertSame(1_800_000, $payload['source_value_minor']);
    }

    public function test_payload_records_both_source_values_for_display(): void
    {
        $payload = $this->build(
            PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE,
            900_000,
            ['line_discount_type' => 'fixed', 'line_discount_value' => 5000.0]
        );

        $this->assertSame(1_000_000, $payload['source_unit_price_minor']);
        $this->assertSame(1_500_000, $payload['source_total_minor']);
    }

    public function test_payload_carries_row_identity_and_reason(): void
    {
        $payload = $this->build(PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE, 1_800_000);

        $this->assertSame(1, $payload['line_id']);
        $this->assertSame(42, $payload['product_id']);
        $this->assertSame('PRODUK UJI', $payload['product_name']);
        $this->assertSame(2, $payload['qty']);
        $this->assertSame(3, $payload['pos_session_id']);
        $this->assertSame(11, $payload['requester_id']);
        $this->assertSame('Koreksi kasir', $payload['reason']);
        $this->assertNotEmpty($payload['fingerprint']);
    }

    public function test_payloads_for_the_two_actions_carry_different_fingerprints(): void
    {
        $unitPrice = $this->build(PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE, 1_000_000);
        $rowTotal = $this->build(PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE, 1_000_000);

        $this->assertNotSame($unitPrice['fingerprint'], $rowTotal['fingerprint']);
    }

    public function test_retired_action_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->build(PosActionApprovalRequest::ACTION_PRICE_OVERRIDE, 900_000);
    }

    public function test_negative_requested_value_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->build(PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE, -1);
    }

    // ------------------------------------------- submitted vs approved

    public function test_matching_submitted_value_is_accepted(): void
    {
        $payload = $this->build(PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE, 1_800_000);

        $this->builder->assertSubmittedValueMatchesApproved(
            $payload,
            PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
            1_800_000
        );

        $this->addToAssertionCount(1);
    }

    public function test_mismatched_submitted_value_is_rejected(): void
    {
        $payload = $this->build(PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE, 1_800_000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('REQUESTED_VALUE_MISMATCH');

        $this->builder->assertSubmittedValueMatchesApproved(
            $payload,
            PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
            1_800_001
        );
    }

    public function test_cross_action_comparison_is_rejected(): void
    {
        // A row-total approval carries no approved unit price, so it can never
        // satisfy a unit-price execution.
        $payload = $this->build(PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE, 1_800_000);

        $this->expectException(DomainException::class);

        $this->builder->assertSubmittedValueMatchesApproved(
            $payload,
            PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE,
            1_800_000
        );
    }
}
