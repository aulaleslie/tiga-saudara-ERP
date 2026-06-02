<?php

namespace Tests\Unit;

use App\Support\ImportSettlementAllocator;
use PHPUnit\Framework\TestCase;

class ImportSettlementAllocatorTest extends TestCase
{
    private ImportSettlementAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new ImportSettlementAllocator();
    }

    /**
     * Every owner's three components must be non-negative and sum to its group total.
     *
     * @param  array<int|string, float>  $groupTotals
     * @param  array<int|string, array{cash:float,deduction:float,due:float}>  $result
     */
    private function assertPerGroupInvariant(array $groupTotals, array $result): void
    {
        foreach ($groupTotals as $key => $total) {
            $parts = $result[$key];
            $this->assertGreaterThanOrEqual(-0.0001, $parts['cash'], "cash for {$key} must be non-negative");
            $this->assertGreaterThanOrEqual(-0.0001, $parts['deduction'], "deduction for {$key} must be non-negative");
            $this->assertGreaterThanOrEqual(-0.0001, $parts['due'], "due for {$key} must be non-negative");
            $this->assertEqualsWithDelta(
                $total,
                $parts['cash'] + $parts['deduction'] + $parts['due'],
                0.001,
                "cash + deduction + due for {$key} must equal its group total"
            );
        }
    }

    /**
     * @param  array<int|string, array{cash:float,deduction:float,due:float}>  $result
     */
    private function assertInvoiceInvariant(array $result, float $paid, float $deduction, float $outstanding): void
    {
        $this->assertEqualsWithDelta($paid, array_sum(array_column($result, 'cash')), 0.01);
        $this->assertEqualsWithDelta($deduction, array_sum(array_column($result, 'deduction')), 0.01);
        $this->assertEqualsWithDelta($outstanding, array_sum(array_column($result, 'due')), 0.01);
    }

    /** @test */
    public function it_does_not_over_settle_a_tiny_group_into_negative_due(): void
    {
        // Reviewer reproduction: a tiny group must not receive cash + deduction exceeding its total.
        $groupTotals = ['small' => 0.14, 'large' => 14.14];
        $result = $this->allocator->allocate($groupTotals, 10.71, 3.57);

        $this->assertGreaterThanOrEqual(0.0, $result['small']['due']);
        $this->assertPerGroupInvariant($groupTotals, $result);
        $this->assertInvoiceInvariant($result, 10.71, 3.57, 0.0); // 14.28 - 10.71 - 3.57 = 0
    }

    /** @test */
    public function it_splits_fully_paid_deducted_invoice_across_equal_owners(): void
    {
        $groupTotals = ['a' => 500000.0, 'b' => 500000.0];
        $result = $this->allocator->allocate($groupTotals, 700000.0, 300000.0);

        $this->assertPerGroupInvariant($groupTotals, $result);
        $this->assertInvoiceInvariant($result, 700000.0, 300000.0, 0.0);
        // Equal groups split each component evenly.
        $this->assertEqualsWithDelta(350000.0, $result['a']['cash'], 0.01);
        $this->assertEqualsWithDelta(150000.0, $result['a']['deduction'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['a']['due'], 0.01);
    }

    /** @test */
    public function it_handles_partial_payment_with_outstanding(): void
    {
        $groupTotals = ['a' => 100000.0, 'b' => 200000.0];
        // total 300000; cash 120000 + deduction 30000 + outstanding 150000.
        $result = $this->allocator->allocate($groupTotals, 120000.0, 30000.0);

        $this->assertPerGroupInvariant($groupTotals, $result);
        $this->assertInvoiceInvariant($result, 120000.0, 30000.0, 150000.0);
    }

    /** @test */
    public function it_handles_zero_deduction_like_a_plain_payment(): void
    {
        $groupTotals = ['a' => 100000.0, 'b' => 200000.0];
        $result = $this->allocator->allocate($groupTotals, 90000.0, 0.0);

        $this->assertPerGroupInvariant($groupTotals, $result);
        $this->assertInvoiceInvariant($result, 90000.0, 0.0, 210000.0);
        foreach ($result as $parts) {
            $this->assertEqualsWithDelta(0.0, $parts['deduction'], 0.01);
        }
    }

    /** @test */
    public function it_settles_a_one_cent_fully_cash_paid_group_as_cash_not_deduction(): void
    {
        // A 0.01 group paid fully in cash with no source deduction must allocate cash 0.01,
        // not misclassify the cent as a deduction credit.
        $groupTotals = ['only' => 0.01];
        $result = $this->allocator->allocate($groupTotals, 0.01, 0.0);

        $this->assertEqualsWithDelta(0.01, $result['only']['cash'], 0.001);
        $this->assertEqualsWithDelta(0.0, $result['only']['deduction'], 0.001);
        $this->assertEqualsWithDelta(0.0, $result['only']['due'], 0.001);
        $this->assertPerGroupInvariant($groupTotals, $result);
        $this->assertInvoiceInvariant($result, 0.01, 0.0, 0.0);
    }

    /** @test */
    public function it_does_not_create_a_deduction_for_a_one_cent_group_with_no_source_deduction(): void
    {
        // groups [0.01, 1.00], cash 1.01, no deduction: cash must sum to 1.01 and deduction to 0,
        // never inventing a POTONGAN credit or exceeding the invoice settlement.
        $groupTotals = ['tiny' => 0.01, 'big' => 1.00];
        $result = $this->allocator->allocate($groupTotals, 1.01, 0.0);

        $this->assertEqualsWithDelta(1.01, array_sum(array_column($result, 'cash')), 0.001);
        $this->assertEqualsWithDelta(0.0, array_sum(array_column($result, 'deduction')), 0.001);
        $this->assertPerGroupInvariant($groupTotals, $result);
        $this->assertInvoiceInvariant($result, 1.01, 0.0, 0.0);
    }

    /** @test */
    public function it_keeps_invariants_across_many_awkward_ratios(): void
    {
        // Fuzz a range of tiny/large group mixes and settlement splits.
        $cases = [
            [['a' => 0.01, 'b' => 0.02, 'c' => 99.97], 50.0, 50.0],
            [['a' => 0.03, 'b' => 33.31, 'c' => 66.66], 70.07, 29.93],
            [['a' => 1.11, 'b' => 2.22, 'c' => 3.33], 5.0, 1.0],
            [['a' => 7.0, 'b' => 0.05], 6.0, 1.05],
            [['a' => 0.01, 'b' => 0.01], 0.01, 0.01],
            [['a' => 0.01, 'b' => 99.99], 50.0, 0.0],
        ];

        foreach ($cases as [$groupTotals, $paid, $deduction]) {
            $result = $this->allocator->allocate($groupTotals, $paid, $deduction);
            $outstanding = round(array_sum($groupTotals) - $paid - $deduction, 2);
            $this->assertPerGroupInvariant($groupTotals, $result);
            $this->assertInvoiceInvariant($result, $paid, $deduction, max($outstanding, 0.0));
        }
    }
}
