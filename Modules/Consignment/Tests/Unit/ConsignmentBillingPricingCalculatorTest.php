<?php

namespace Modules\Consignment\Tests\Unit;

use Modules\Consignment\Services\ConsignmentBillingPricingCalculator;
use Tests\TestCase;

class ConsignmentBillingPricingCalculatorTest extends TestCase
{
    protected ConsignmentBillingPricingCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ConsignmentBillingPricingCalculator();
    }

    protected function evidence(float $qty, float $unitPrice, ?int $taxId = null, float $taxRate = 0.0): array
    {
        $subTotal = round($qty * $unitPrice, 2);
        $taxAmount = round($subTotal * ($taxRate / 100), 2);

        return [
            'quantity' => $qty,
            'original_unit_price' => $unitPrice,
            'original_sub_total' => $subTotal,
            'original_tax_amount' => $taxAmount,
            'original_total' => round($subTotal + $taxAmount, 2),
            'allocation_tax_id' => $taxId,
            'allocation_tax_rate' => $taxRate,
        ];
    }

    /** @test */
    public function confirmation_3_fixed_discount_example_yields_expected_payable()
    {
        // 9 units + 3 units @ Rp4,200,000, Rp10,000 fixed discount per unit, non-PKP, no global discount.
        $row1 = $this->calculator->calculateRow(
            $this->evidence(9, 4200000),
            ['discount_type' => 'fixed', 'discount_value' => 10000],
            false,
            false,
            false
        );
        $row2 = $this->calculator->calculateRow(
            $this->evidence(3, 4200000),
            ['discount_type' => 'fixed', 'discount_value' => 10000],
            false,
            false,
            false
        );

        $this->assertEmpty($row1['errors']);
        $this->assertEmpty($row2['errors']);

        $payable = round($row1['total'] + $row2['total'], 2);

        $this->assertEquals(50280000.0, $payable);
    }

    /** @test */
    public function percentage_row_discount_reduces_effective_unit_price()
    {
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000),
            ['discount_type' => 'percentage', 'discount_value' => 10],
            false,
            false,
            false
        );

        $this->assertEmpty($row['errors']);
        $this->assertEquals(900000.0, $row['sub_total']);
        $this->assertEquals(900000.0, $row['total']);
    }

    /** @test */
    public function percentage_row_discount_above_100_is_rejected()
    {
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000),
            ['discount_type' => 'percentage', 'discount_value' => 150],
            false,
            false,
            false
        );

        $this->assertNotEmpty($row['errors']);
    }

    /** @test */
    public function negative_effective_price_from_fixed_discount_is_rejected()
    {
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 1000),
            ['discount_type' => 'fixed', 'discount_value' => 2000],
            false,
            false,
            false
        );

        $this->assertNotEmpty($row['errors']);
        $this->assertEquals(0.0, $row['total']);
    }

    /** @test */
    public function row_total_override_back_solves_unit_price_and_is_authoritative()
    {
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000),
            ['row_total_override' => 950000],
            false,
            false,
            false
        );

        $this->assertEmpty($row['errors']);
        $this->assertEquals(950000.0, $row['total']);
        $this->assertEquals(95000.0, $row['unit_price']);
    }

    /** @test */
    public function unchanged_submission_preserves_legacy_total_exactly()
    {
        $evidence = $this->evidence(5, 50000, 1, 11);
        $evidence['original_tax_amount'] = 27500.0;
        $evidence['original_sub_total'] = 250000.0;
        $evidence['original_total'] = 277500.0;

        $row = $this->calculator->calculateRow($evidence, [], true, true, true);

        $this->assertEmpty($row['errors']);
        $this->assertEquals(277500.0, $row['total']);
        $this->assertEquals(250000.0, $row['sub_total']);
        $this->assertEquals(27500.0, $row['tax_amount']);
    }

    /** @test */
    public function unchanged_pkp_row_gross_unit_price_reconciles_to_dpp_plus_tax()
    {
        $evidence = $this->evidence(5, 50000, 1, 11);
        $evidence['original_tax_amount'] = 27500.0;
        $evidence['original_sub_total'] = 250000.0;
        $evidence['original_total'] = 277500.0;

        $row = $this->calculator->calculateRow($evidence, [], true, true, true);

        // Gross unit price (tax-inclusive) reproduces the original total / qty.
        $this->assertEquals(55500.0, $row['unit_price']);
    }

    /** @test */
    public function pkp_default_tax_included_computes_gross_row_total()
    {
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000, 1, 11),
            [],
            true,
            true,
            false
        );

        $this->assertEmpty($row['errors']);
        // No edits submitted at all, but explicitly passed as "changed" (isUnchanged
        // false): the displayed baseline for a tax-included PKP row is the gross price
        // that reproduces original_total (1,110,000 for 10 units @ 100,000 + 11% tax),
        // so a no-op edit reproduces that same gross total exactly.
        $this->assertEquals(1110000.0, $row['total']);
        $this->assertEquals(1000000.0, round($row['sub_total'], 1));
    }

    /** @test */
    public function pkp_tax_exclusive_toggle_grosses_up_the_row_total()
    {
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000, 1, 11),
            [],
            true,
            false,
            false
        );

        $this->assertEmpty($row['errors']);
        $this->assertEquals(1000000.0, $row['sub_total']);
        $this->assertEquals(110000.0, $row['tax_amount']);
        $this->assertEquals(1110000.0, $row['total']);
    }

    /** @test */
    public function global_discount_percentage_cannot_exceed_sum_of_row_totals()
    {
        $result = $this->calculator->applyGlobalDiscount(100000, 'percentage', 150);

        $this->assertNotEmpty($result['errors']);
        $this->assertEquals(100000.0, $result['discount_amount']);
        $this->assertEquals(0.0, $result['total_amount']);
    }

    /** @test */
    public function global_discount_fixed_cannot_exceed_sum_of_row_totals()
    {
        $result = $this->calculator->applyGlobalDiscount(50000, 'fixed', 90000);

        $this->assertNotEmpty($result['errors']);
        $this->assertEquals(50000.0, $result['discount_amount']);
        $this->assertEquals(0.0, $result['total_amount']);
    }

    /** @test */
    public function global_discount_within_bounds_reduces_payable_without_touching_tax()
    {
        $result = $this->calculator->applyGlobalDiscount(1110000, 'fixed', 10000);

        $this->assertEmpty($result['errors']);
        $this->assertEquals(10000.0, $result['discount_amount']);
        $this->assertEquals(1100000.0, $result['total_amount']);
    }

    /** @test */
    public function row_id_is_stable_regardless_of_allocation_order()
    {
        $id1 = $this->calculator->buildRowId('grp-key', [3, 1, 2]);
        $id2 = $this->calculator->buildRowId('grp-key', [2, 3, 1]);

        $this->assertEquals($id1, $id2);
    }

    /** @test */
    public function changing_tax_selection_uses_the_resolved_rate_not_the_allocation_rate()
    {
        // Allocation was taxed at 11%, operator selects a different tax whose active
        // rate (resolved server-side) is 10% -- the calculation must use 10%, not
        // silently fall back to the allocation's original 11%.
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000, 1, 11),
            ['tax_id' => 2],
            true,
            false,
            false,
            10.0 // resolved rate for tax_id 2
        );

        $this->assertEmpty($row['errors']);
        $this->assertEquals(10.0, $row['tax_rate']);
        $this->assertEquals(100000.0, $row['tax_amount']);
        $this->assertEquals(1100000.0, $row['total']);
    }

    /** @test */
    public function changing_tax_selection_without_a_resolved_rate_is_rejected()
    {
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000, 1, 11),
            ['tax_id' => 2],
            true,
            false,
            false,
            null // caller failed to resolve/validate the new tax id
        );

        $this->assertNotEmpty($row['errors']);
    }

    /** @test */
    public function row_total_override_with_fixed_discount_back_solves_a_consistent_unit_price()
    {
        // 10 units, Rp5,000 fixed discount/unit, override total to Rp900,000 (non-PKP).
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000),
            ['discount_type' => 'fixed', 'discount_value' => 5000, 'row_total_override' => 900000],
            false,
            false,
            false
        );

        $this->assertEmpty($row['errors']);
        $this->assertEquals(900000.0, $row['total']);
        // The saved unit price and discount must reproduce the saved total:
        // (unit_price - discount) * qty === total.
        $this->assertEqualsWithDelta(
            $row['total'],
            round(($row['unit_price'] - $row['discount_amount']) * 10, 2),
            0.01
        );
        $this->assertEquals(95000.0, $row['unit_price']);
    }

    /** @test */
    public function row_total_override_with_percentage_discount_back_solves_a_consistent_unit_price()
    {
        // 10 units, 10% discount, override total to Rp900,000 (non-PKP).
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000),
            ['discount_type' => 'percentage', 'discount_value' => 10, 'row_total_override' => 900000],
            false,
            false,
            false
        );

        $this->assertEmpty($row['errors']);
        $this->assertEquals(900000.0, $row['total']);
        $effectivePrice = $row['unit_price'] * (1 - 10 / 100);
        $this->assertEqualsWithDelta(900000.0, round($effectivePrice * 10, 2), 0.01);
    }

    /** @test */
    public function pkp_tax_included_discount_does_not_silently_drop_the_tax_portion()
    {
        // 10 units, allocation DPP 100,000/unit, 11% tax, tax-included. The gross
        // (displayed) unit price is 111,000. A flat Rp10,000 discount must be taken
        // off that gross baseline, not off the tax-exclusive DPP, or the payable
        // drops by far more than the entered discount.
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000, 1, 11),
            ['discount_type' => 'fixed', 'discount_value' => 10000],
            true,
            true,
            false
        );

        $this->assertEmpty($row['errors']);
        // Gross baseline 111,000 - 10,000 discount = 101,000/unit * 10 = 1,010,000.
        $this->assertEquals(1010000.0, $row['total']);
    }

    /** @test */
    public function pkp_row_total_override_with_tax_included_reproduces_the_saved_total_exactly()
    {
        // Reproduces the reported case: 10-unit row, 11% tax, tax-included, overridden
        // total of Rp900, with a Rp10 fixed discount entered. The saved unit price and
        // discount must reproduce exactly Rp900, not ~Rp810.81.
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000, 1, 11), // unit_price value here is irrelevant to the override path
            ['discount_type' => 'fixed', 'discount_value' => 10, 'row_total_override' => 900],
            true,
            true,
            false
        );

        $this->assertEmpty($row['errors']);
        $this->assertEquals(900.0, $row['total']);

        $reproducedTotal = round(($row['unit_price'] - $row['discount_amount']) * 10, 2);
        $this->assertEqualsWithDelta(900.0, $reproducedTotal, 0.01);
    }

    /** @test */
    public function percentage_discount_with_row_total_override_reproduces_the_saved_total_exactly()
    {
        // Reproduces the reported case: 10-unit row, 11% tax, tax-included, a 10%
        // percentage discount, overridden total of Rp800. The discount amount must be
        // recomputed against the back-solved unit price, not the pre-override one, or
        // the saved unit_price/discount_amount pair reproduces the wrong total
        // (previously ~Rp777.89 instead of Rp800).
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000, 1, 11),
            ['discount_type' => 'percentage', 'discount_value' => 10, 'row_total_override' => 800],
            true,
            true,
            false
        );

        $this->assertEmpty($row['errors']);
        $this->assertEquals(800.0, $row['total']);

        $reproducedTotal = round(($row['unit_price'] - $row['discount_amount']) * 10, 2);
        $this->assertEqualsWithDelta(800.0, $reproducedTotal, 0.01);
    }

    /** @test */
    public function hundred_percent_discount_rejects_a_nonzero_row_total_override()
    {
        // A 100% discount forces a zero effective unit price for every row, so a
        // positive override total can never be reconciled with the saved
        // unit_price/discount_amount pair -- it must be rejected, not silently accepted.
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000),
            ['discount_type' => 'percentage', 'discount_value' => 100, 'row_total_override' => 500],
            false,
            false,
            false
        );

        $this->assertNotEmpty($row['errors']);
    }

    /** @test */
    public function hundred_percent_discount_accepts_a_zero_row_total_override()
    {
        $row = $this->calculator->calculateRow(
            $this->evidence(10, 100000),
            ['discount_type' => 'percentage', 'discount_value' => 100, 'row_total_override' => 0],
            false,
            false,
            false
        );

        $this->assertEmpty($row['errors']);
        $this->assertEquals(0.0, $row['total']);
    }

    /** @test */
    public function a_six_decimal_unit_price_survives_calculation_and_persistence_unchanged()
    {
        // 45000.333 must round-trip exactly through the calculator (canonical
        // storage keeps up to 6 decimal places), matching what the display layer
        // shows at 2 decimals but never actually rounds the stored value to.
        $row = $this->calculator->calculateRow(
            $this->evidence(1, 45000.333),
            ['unit_price' => 45000.333],
            false,
            false,
            false
        );

        $this->assertEmpty($row['errors']);
        $this->assertEquals(45000.333, $row['unit_price']);
        $this->assertEquals(45000.33, $row['total']);
    }

    /** @test */
    public function row_total_override_back_solves_unit_price_for_a_non_terminating_division()
    {
        // Rp100 total across 3 units -> unit price is a repeating decimal
        // (33.333333...); the calculator must round it to exactly 6 decimal
        // places rather than truncating or losing precision, and the saved
        // unit price must reproduce the saved Rp100 total to the cent.
        $row = $this->calculator->calculateRow(
            $this->evidence(3, 100),
            ['row_total_override' => 100],
            false,
            false,
            false
        );

        $this->assertEmpty($row['errors']);
        $this->assertEquals(100.0, $row['total']);
        $this->assertEquals(33.333333, $row['unit_price']);
        $this->assertEqualsWithDelta(100.0, round($row['unit_price'] * 3, 2), 0.01);
    }

    /** @test */
    public function a_unit_price_near_the_decimal_15_6_column_limit_is_accepted()
    {
        // purchase_details.unit_price/price are DECIMAL(15,6): 9 integer digits
        // before the point, so the largest representable value is
        // 999999999.999999. A value just under that limit must be accepted and
        // preserved to the cent in the resulting row total.
        $largePrice = 999999999.999999;

        $row = $this->calculator->calculateRow(
            $this->evidence(1, $largePrice),
            ['unit_price' => $largePrice],
            false,
            false,
            false
        );

        $this->assertEmpty($row['errors']);
        $this->assertEquals($largePrice, $row['unit_price']);
        $this->assertEqualsWithDelta($largePrice, $row['total'], 0.01);
    }

    /** @test */
    public function a_value_beyond_the_decimal_15_6_column_limit_is_rejected()
    {
        $row = $this->calculator->calculateRow(
            $this->evidence(1, 100),
            ['unit_price' => 1000000000.0],
            false,
            false,
            false
        );

        $this->assertNotEmpty($row['errors']);
    }

    /** @test */
    public function an_untouched_row_preserves_the_legacy_total_even_when_evidence_has_fractional_quantity()
    {
        // A row the operator never edited (isUnchanged = true) must reproduce
        // the exact original allocation-based total regardless of fractional
        // quantity or unit price precision -- no rounding drift is introduced
        // just because the row was displayed.
        $evidence = $this->evidence(2.75, 45000.333);

        $row = $this->calculator->calculateRow($evidence, [], false, false, true);

        $this->assertEmpty($row['errors']);
        $this->assertEquals($evidence['original_sub_total'], $row['total']);
        $this->assertEquals($evidence['original_unit_price'], $row['unit_price']);
    }

    /** @test */
    public function row_total_override_within_its_own_range_rejects_when_the_back_solved_unit_price_overflows()
    {
        // A row total of Rp999,999,999 is well within DECIMAL(15,2) (max
        // 9999999999999.99), but over a fractional quantity of 0.01 it back-solves
        // to a unit price of Rp99,999,999,900 -- far beyond DECIMAL(15,6)'s
        // 999999999.999999 ceiling. The initial unit-price check never sees this
        // value (no unit_price was submitted), so it must be caught after
        // back-solving or it would fail only later at persistence.
        $row = $this->calculator->calculateRow(
            $this->evidence(0.01, 100),
            ['row_total_override' => 999999999],
            false,
            false,
            false
        );

        $this->assertNotEmpty($row['errors']);
    }

    /** @test */
    public function row_total_override_near_the_decimal_15_2_limit_is_accepted_with_a_sane_quantity()
    {
        // Confirms the fix does not over-reject: a large total over an ordinary
        // (non-tiny-fractional) quantity keeps its back-solved unit price within
        // range and is accepted.
        $row = $this->calculator->calculateRow(
            $this->evidence(1000, 500000),
            ['row_total_override' => 500000000],
            false,
            false,
            false
        );

        $this->assertEmpty($row['errors']);
        $this->assertEquals(500000000.0, $row['total']);
        $this->assertEquals(500000.0, $row['unit_price']);
    }
}
