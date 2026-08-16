<?php

namespace Modules\Pos\Tests\Unit;

use Modules\Pos\Services\PosCartTotalsCalculator;
use Modules\Pos\Services\PosRowOverrideArithmetic;
use Tests\TestCase;

/**
 * Reconciliation guarantees for overridden rows through checkout and receipts.
 *
 * Two properties matter:
 *
 *   1. Unit price, row discount, bill discount, and the final charged amount
 *      must each be reportable without inferring one from the others, and
 *      without recomputing an overridden total from a rounded unit price.
 *
 * Split-owner allocation exactness is verified against the REAL planner and
 * posting adapter in PosRowOverrideCheckoutIntegrationTest; mirroring that
 * private algorithm here would only prove the copy.
 */
class PosOverrideCheckoutReconciliationTest extends TestCase
{
    private PosRowOverrideArithmetic $arithmetic;
    private PosCartTotalsCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arithmetic = new PosRowOverrideArithmetic();
        $this->calculator = new PosCartTotalsCalculator();
    }

    // ------------------------------------ receipt field separation

    public function test_receipt_amounts_are_separable_without_recomputation(): void
    {
        // Rp20.000 gross, Rp5.000 row discount => Rp15.000 net.
        $result = $this->arithmetic->applyUnitPrice(
            unitPriceMinor: 1_000_000,
            qty: 2,
            discountType: 'fixed',
            discountValue: 5000.0
        );

        $gross = (int) $result['line_gross_minor'];
        $rowDiscount = (int) $result['line_discount_minor'];
        $net = (int) $result['line_net_minor'];

        // Each figure stands on its own, and they reconcile.
        $this->assertSame(2_000_000, $gross);
        $this->assertSame(500_000, $rowDiscount);
        $this->assertSame(1_500_000, $net);
        $this->assertSame($net, $gross - $rowDiscount);
    }

    public function test_bill_discount_is_distinct_from_the_row_discount(): void
    {
        $line = [
            'line_id' => 1,
            'qty' => 2,
            'unit_price' => 10000.0,
            'price_source' => 'LINE_UNIT_PRICE_OVERRIDE',
            'line_discount_type' => 'fixed',
            'line_discount_value' => 5000.0,
            'tax_id' => null,
            'tax_rate' => 0.0,
            'line_gross_minor' => 2_000_000,
            'line_discount_minor' => 500_000,
            'line_net_minor' => 1_500_000,
            'line_tax_minor' => 0,
            'line_taxable_base_minor' => 1_500_000,
        ];

        // A Rp3.000 bill discount applies AFTER the row's authoritative net.
        $calculated = $this->calculator->calculate([$line], ['type' => 'fixed', 'value' => 3000], false);
        $row = $calculated['lines'][0];

        $this->assertSame(15000.0, (float) $row['line_net_before_bill'], 'Row net must be the pre-bill amount.');
        $this->assertSame(5000.0, (float) $row['line_discount_amount'], 'Row discount reported separately.');
        $this->assertSame(3000.0, (float) $row['bill_discount_amount'], 'Bill discount reported separately.');
        $this->assertSame(12000.0, (float) $row['line_total'], 'Charged amount is net minus bill discount.');

        // And the four figures reconcile without inferring any of them.
        $this->assertSame(
            (float) $row['line_total'],
            (float) $row['line_net_before_bill'] - (float) $row['bill_discount_amount']
        );
    }

    public function test_bill_discount_does_not_alter_the_authoritative_row_net(): void
    {
        $makeLine = fn (): array => [
            'line_id' => 1,
            'qty' => 3,
            'unit_price' => 3333.33,
            'price_source' => 'LINE_TOTAL_OVERRIDE',
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0.0,
            'tax_id' => null,
            'tax_rate' => 0.0,
            'line_gross_minor' => 1_000_000,
            'line_discount_minor' => 0,
            'line_net_minor' => 1_000_000,
            'line_tax_minor' => 0,
            'line_taxable_base_minor' => 1_000_000,
        ];

        $without = $this->calculator->calculate([$makeLine()], ['type' => 'fixed', 'value' => 0], false);
        $with = $this->calculator->calculate([$makeLine()], ['type' => 'fixed', 'value' => 2500], false);

        $this->assertSame(
            (float) $without['lines'][0]['line_net_before_bill'],
            (float) $with['lines'][0]['line_net_before_bill'],
            'A bill discount must not change the row net the override established.'
        );
        $this->assertSame(7500.0, (float) $with['lines'][0]['line_total']);
    }

    public function test_overridden_row_total_is_never_recomputed_from_rounded_unit_price(): void
    {
        // Rp10.000 over qty 3: the derived unit price rounds, so multiplying it
        // back out would drift away from the authoritative total.
        $result = $this->arithmetic->applyRowTotal(requestedNetMinor: 1_000_000, qty: 3);

        $line = [
            'line_id' => 1,
            'qty' => 3,
            'unit_price' => round(((int) $result['unit_price_minor']) / 100, 2),
            'price_source' => 'LINE_TOTAL_OVERRIDE',
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0.0,
            'tax_id' => null,
            'tax_rate' => 0.0,
            'line_gross_minor' => (int) $result['line_gross_minor'],
            'line_discount_minor' => (int) $result['line_discount_minor'],
            'line_net_minor' => (int) $result['line_net_minor'],
            'line_tax_minor' => (int) $result['line_tax_minor'],
            'line_taxable_base_minor' => (int) $result['line_taxable_base_minor'],
        ];

        $calculated = $this->calculator->calculate([$line], ['type' => 'fixed', 'value' => 0], false);

        $this->assertSame(
            10000.0,
            (float) $calculated['lines'][0]['line_net_before_bill'],
            'The authoritative total drifted; it was recomputed rather than consumed.'
        );
        $this->assertNotSame(
            10000.0,
            round($line['unit_price'] * 3, 2),
            'This case is only meaningful when the rounded unit price does not reproduce the total.'
        );
    }
}
