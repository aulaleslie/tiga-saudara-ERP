<?php

namespace Modules\Pos\Tests\Unit;

use DomainException;
use Modules\Pos\Services\PosRowOverrideArithmetic;
use Tests\TestCase;

/**
 * The canonical minor-unit arithmetic for both row overrides.
 *
 * Every amount here is integer minor units (cents), so Rp10.000 is 1_000_000.
 */
class PosRowOverrideArithmeticTest extends TestCase
{
    private PosRowOverrideArithmetic $arithmetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arithmetic = new PosRowOverrideArithmetic();
    }

    // --------------------------------------------------------- unit price

    public function test_unit_price_without_discount(): void
    {
        $result = $this->arithmetic->applyUnitPrice(
            unitPriceMinor: 900_000, // Rp9.000
            qty: 4
        );

        $this->assertSame('LINE_UNIT_PRICE_OVERRIDE', $result['price_source']);
        $this->assertSame(900_000, $result['unit_price_minor']);
        $this->assertSame(3_600_000, $result['line_gross_minor']); // Rp36.000
        $this->assertSame(0, $result['line_discount_minor']);
        $this->assertSame(3_600_000, $result['line_net_minor']);
        $this->assertSame(0, $result['line_tax_minor']);
    }

    public function test_unit_price_applies_a_fixed_discount_exactly_once(): void
    {
        $result = $this->arithmetic->applyUnitPrice(
            unitPriceMinor: 500_000, // Rp5.000
            qty: 3,                  // gross Rp15.000
            discountType: 'fixed',
            discountValue: 1_000.0   // Rp1.000
        );

        $this->assertSame(1_500_000, $result['line_gross_minor']);
        $this->assertSame(100_000, $result['line_discount_minor']);
        $this->assertSame(1_400_000, $result['line_net_minor']);
    }

    public function test_unit_price_applies_a_percentage_discount(): void
    {
        $result = $this->arithmetic->applyUnitPrice(
            unitPriceMinor: 1_000_000, // Rp10.000
            qty: 2,                    // gross Rp20.000
            discountType: 'percentage',
            discountValue: 10.0
        );

        $this->assertSame(2_000_000, $result['line_gross_minor']);
        $this->assertSame(200_000, $result['line_discount_minor']);
        $this->assertSame(1_800_000, $result['line_net_minor']);
    }

    public function test_unit_price_rejects_a_negative_value(): void
    {
        $this->expectException(DomainException::class);
        $this->arithmetic->applyUnitPrice(unitPriceMinor: -1, qty: 1);
    }

    public function test_zero_unit_price_is_accepted(): void
    {
        $result = $this->arithmetic->applyUnitPrice(unitPriceMinor: 0, qty: 3);

        $this->assertSame(0, $result['line_gross_minor']);
        $this->assertSame(0, $result['line_net_minor']);
    }

    // ---------------------------------------------------------- row total

    public function test_row_total_with_fixed_discount_reverses_exactly(): void
    {
        // Requested net Rp10.000 with a fixed Rp1.000 discount
        //   -> gross Rp11.000, discount Rp1.000, net exactly Rp10.000.
        $result = $this->arithmetic->applyRowTotal(
            requestedNetMinor: 1_000_000,
            qty: 1,
            discountType: 'fixed',
            discountValue: 1_000.0
        );

        $this->assertSame('LINE_TOTAL_OVERRIDE', $result['price_source']);
        $this->assertSame(1_100_000, $result['line_gross_minor']);
        $this->assertSame(100_000, $result['line_discount_minor']);
        $this->assertSame(1_000_000, $result['line_net_minor']);
    }

    public function test_row_total_with_ten_percent_discount_reverses_exactly(): void
    {
        // The worked example: requested net 10.000 at 10%
        //   -> gross 11.111, discount 1.111, net exactly 10.000.
        $result = $this->arithmetic->applyRowTotal(
            requestedNetMinor: 1_000_000,
            qty: 1,
            discountType: 'percentage',
            discountValue: 10.0
        );

        $this->assertSame(1_111_111, $result['line_gross_minor']);
        $this->assertSame(111_111, $result['line_discount_minor']);
        $this->assertSame(
            1_000_000,
            $result['line_net_minor'],
            'The requested net must survive the reversal exactly.'
        );

        // And the invariant that makes it exact: gross - discount == net.
        $this->assertSame(
            $result['line_net_minor'],
            $result['line_gross_minor'] - $result['line_discount_minor']
        );
    }

    public function test_row_total_reversal_is_exact_across_awkward_percentages(): void
    {
        foreach ([3.0, 7.5, 12.5, 33.3, 66.67, 99.9] as $percentage) {
            $result = $this->arithmetic->applyRowTotal(
                requestedNetMinor: 1_000_000,
                qty: 3,
                discountType: 'percentage',
                discountValue: $percentage
            );

            $this->assertSame(
                1_000_000,
                $result['line_net_minor'],
                "Net drifted at {$percentage}%."
            );
            $this->assertSame(
                $result['line_net_minor'],
                $result['line_gross_minor'] - $result['line_discount_minor'],
                "gross - discount != net at {$percentage}%."
            );
        }
    }

    public function test_row_total_survives_non_divisible_quantities(): void
    {
        // Rp10.000 over qty 3 does not divide evenly; the total is authoritative
        // and the derived unit price is display-only.
        $result = $this->arithmetic->applyRowTotal(
            requestedNetMinor: 1_000_000,
            qty: 3
        );

        $this->assertSame(1_000_000, $result['line_net_minor']);
        $this->assertNotSame(
            $result['line_net_minor'],
            $result['unit_price_minor'] * 3,
            'Rounded unit price must not be expected to reproduce the row total.'
        );
    }

    public function test_row_total_rejects_percentage_at_or_above_one_hundred(): void
    {
        $this->expectException(DomainException::class);
        $this->arithmetic->applyRowTotal(
            requestedNetMinor: 1_000_000,
            qty: 1,
            discountType: 'percentage',
            discountValue: 100.0
        );
    }

    public function test_row_total_rejects_a_negative_percentage(): void
    {
        $this->expectException(DomainException::class);
        $this->arithmetic->applyRowTotal(
            requestedNetMinor: 1_000_000,
            qty: 1,
            discountType: 'percentage',
            discountValue: -5.0
        );
    }

    public function test_row_total_rejects_a_negative_total(): void
    {
        $this->expectException(DomainException::class);
        $this->arithmetic->applyRowTotal(requestedNetMinor: -1, qty: 1);
    }

    public function test_zero_row_total_is_accepted(): void
    {
        $result = $this->arithmetic->applyRowTotal(requestedNetMinor: 0, qty: 2);

        $this->assertSame(0, $result['line_net_minor']);
        $this->assertSame(0, $result['line_tax_minor']);
    }

    // ---------------------------------------------------------------- tax

    public function test_pkp_row_reconciles_taxable_base_plus_tax_to_the_net(): void
    {
        $result = $this->arithmetic->applyRowTotal(
            requestedNetMinor: 1_000_000,
            qty: 3,
            taxRate: 11.0,
            isPkp: true
        );

        $this->assertSame(1_000_000, $result['line_net_minor']);
        $this->assertSame(
            $result['line_net_minor'],
            $result['line_taxable_base_minor'] + $result['line_tax_minor'],
            'Taxable base plus tax must equal the authoritative net exactly.'
        );
        $this->assertGreaterThan(0, $result['line_tax_minor']);
    }

    public function test_non_pkp_row_carries_no_tax(): void
    {
        $result = $this->arithmetic->applyRowTotal(
            requestedNetMinor: 1_000_000,
            qty: 1,
            taxRate: 11.0,
            isPkp: false
        );

        $this->assertSame(0, $result['line_tax_minor']);
        $this->assertSame(
            $result['line_net_minor'],
            $result['line_taxable_base_minor'],
            'A non-PKP pre-tax base must equal the row total.'
        );
    }

    public function test_pkp_unit_price_row_also_reconciles(): void
    {
        $result = $this->arithmetic->applyUnitPrice(
            unitPriceMinor: 333_333,
            qty: 3,
            taxRate: 11.0,
            isPkp: true
        );

        $this->assertSame(
            $result['line_net_minor'],
            $result['line_taxable_base_minor'] + $result['line_tax_minor']
        );
    }
}
