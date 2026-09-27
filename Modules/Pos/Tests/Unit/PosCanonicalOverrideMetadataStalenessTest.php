<?php

namespace Modules\Pos\Tests\Unit;

use Modules\Pos\Services\PosCartTotalsCalculator;
use Modules\Pos\Services\PosRowOverrideArithmetic;
use Tests\TestCase;

/**
 * Canonical override metadata must never outlive the row state it was computed
 * for.
 *
 * PosCartTotalsCalculator trusts line_gross_minor / line_discount_minor /
 * line_net_minor over recalculating, which is what makes the persisted metadata
 * authoritative. The same property makes stale metadata dangerous: a set
 * computed at qty 2 would keep reporting that total at qty 3.
 *
 * These tests pin the calculator's half of the contract. The service-level
 * mutation paths are covered in PosCartOverrideMetadataRefreshTest.
 */
class PosCanonicalOverrideMetadataStalenessTest extends TestCase
{
    private PosCartTotalsCalculator $calculator;
    private PosRowOverrideArithmetic $arithmetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PosCartTotalsCalculator();
        $this->arithmetic = new PosRowOverrideArithmetic();
    }

    /**
     * A line carrying canonical metadata from a unit-price override.
     *
     * @return array<string, mixed>
     */
    private function overriddenLine(int $qty, array $overrides = []): array
    {
        $result = $this->arithmetic->applyUnitPrice(
            unitPriceMinor: 1_000_000, // Rp10.000
            qty: $qty,
            discountType: (string) ($overrides['line_discount_type'] ?? 'fixed'),
            discountValue: (float) ($overrides['line_discount_value'] ?? 0.0)
        );

        return array_merge([
            'line_id' => 1,
            'product_id' => 42,
            'qty' => $qty,
            'unit_price' => 10000.0,
            'price_source' => 'LINE_UNIT_PRICE_OVERRIDE',
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0.0,
            'tax_id' => null,
            'tax_rate' => 0.0,
            'line_total' => (int) $result['line_net_minor'],
            'line_gross_minor' => (int) $result['line_gross_minor'],
            'line_discount_minor' => (int) $result['line_discount_minor'],
            'line_net_minor' => (int) $result['line_net_minor'],
            'line_tax_minor' => (int) $result['line_tax_minor'],
            'line_taxable_base_minor' => (int) $result['line_taxable_base_minor'],
        ], $overrides);
    }

    private function calculateNet(array $line): float
    {
        $result = $this->calculator->calculate([$line], ['type' => 'fixed', 'value' => 0], false);

        return (float) $result['lines'][0]['line_net_before_bill'];
    }

    public function test_canonical_metadata_is_authoritative_when_consistent(): void
    {
        // Rp10.000 x 2 = Rp20.000.
        $this->assertSame(20000.0, $this->calculateNet($this->overriddenLine(2)));
    }

    public function test_stale_metadata_would_misreport_the_total(): void
    {
        // This is the defect being guarded against: metadata computed at qty 2
        // left on a line whose qty is now 3 keeps reporting the old total.
        $stale = $this->overriddenLine(2);
        $stale['qty'] = 3;

        $this->assertSame(
            20000.0,
            $this->calculateNet($stale),
            'Confirms the calculator trusts persisted metadata, so mutation paths must refresh it.'
        );
    }

    public function test_refreshed_metadata_reports_the_new_quantity(): void
    {
        // After a refresh the same row reports Rp30.000, as it must.
        $this->assertSame(30000.0, $this->calculateNet($this->overriddenLine(3)));
    }

    public function test_stripping_metadata_falls_back_to_recalculation(): void
    {
        // With canonical fields removed the calculator recomputes from
        // qty x unit_price, so a stripped line is never stale.
        $stripped = $this->overriddenLine(2);
        $stripped['qty'] = 3;

        foreach ([
            'line_total',
            'line_gross_minor',
            'line_discount_minor',
            'line_net_minor',
            'line_tax_minor',
            'line_taxable_base_minor',
        ] as $field) {
            unset($stripped[$field]);
        }

        $this->assertSame(30000.0, $this->calculateNet($stripped));
    }

    public function test_partial_metadata_is_not_trusted(): void
    {
        // Removing only line_total (the old behaviour) must not leave the rest
        // of the set trusted, or the stale total survives.
        $partial = $this->overriddenLine(2);
        $partial['qty'] = 3;
        unset($partial['line_total']);

        $this->assertSame(
            20000.0,
            $this->calculateNet($partial),
            'Partial clearing still yields a stale total; all canonical fields must be removed together.'
        );
    }

    public function test_refreshed_metadata_reflects_a_changed_fixed_discount(): void
    {
        $line = $this->overriddenLine(2, [
            'line_discount_type' => 'fixed',
            'line_discount_value' => 5000.0,
        ]);

        // Rp20.000 gross less Rp5.000 = Rp15.000.
        $this->assertSame(15000.0, $this->calculateNet($line));
    }

    public function test_refreshed_metadata_reflects_a_changed_percentage_discount(): void
    {
        $line = $this->overriddenLine(2, [
            'line_discount_type' => 'percentage',
            'line_discount_value' => 10.0,
        ]);

        // Rp20.000 gross less 10% = Rp18.000.
        $this->assertSame(18000.0, $this->calculateNet($line));
    }

    public function test_standard_lines_are_unaffected_by_the_canonical_path(): void
    {
        $standard = [
            'line_id' => 1,
            'qty' => 3,
            'unit_price' => 10000.0,
            'price_source' => 'BASE',
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0.0,
            'tax_id' => null,
            'tax_rate' => 0.0,
        ];

        $this->assertSame(30000.0, $this->calculateNet($standard));
    }
}
