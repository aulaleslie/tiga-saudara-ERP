<?php

namespace Tests\Unit\Support;

use App\Support\RowTotalRoundingCalculator;
use PHPUnit\Framework\TestCase;

class RowTotalRoundingCalculatorTest extends TestCase
{
    public function test_disabled_or_zero_increment_returns_unrounded_two_decimals(): void
    {
        $this->assertEquals(78999.96, RowTotalRoundingCalculator::round(78999.96, 0));
        $this->assertEquals(78999.96, RowTotalRoundingCalculator::round(78999.96, 0.00));
        $this->assertEquals(78999.96, RowTotalRoundingCalculator::round(78999.96, -100));
    }

    public function test_boundary_cases_with_100_increment(): void
    {
        // 78999.96 -> 79000.00
        $this->assertEquals(79000.00, RowTotalRoundingCalculator::round(78999.96, 100.00));

        // 78899.96 -> 78900.00
        $this->assertEquals(78900.00, RowTotalRoundingCalculator::round(78899.96, 100.00));

        // Midpoint: 78950.00 -> 79000.00
        $this->assertEquals(79000.00, RowTotalRoundingCalculator::round(78950.00, 100.00));

        // Below midpoint: 78949.00 -> 78900.00
        $this->assertEquals(78900.00, RowTotalRoundingCalculator::round(78949.00, 100.00));
    }

    public function test_custom_increments(): void
    {
        // Increment = 50.00
        $this->assertEquals(78950.00, RowTotalRoundingCalculator::round(78925.00, 50.00));
        $this->assertEquals(78900.00, RowTotalRoundingCalculator::round(78924.99, 50.00));

        // Increment = 500.00
        $this->assertEquals(79000.00, RowTotalRoundingCalculator::round(78750.00, 500.00));
        $this->assertEquals(78500.00, RowTotalRoundingCalculator::round(78749.99, 500.00));

        // Increment = 0.50
        $this->assertEquals(10.50, RowTotalRoundingCalculator::round(10.25, 0.50));
        $this->assertEquals(10.00, RowTotalRoundingCalculator::round(10.24, 0.50));
    }

    public function test_small_positive_values(): void
    {
        // 25.00 with increment 100.00 rounds to 0.00 (half-up boundary is 50.00)
        $this->assertEquals(0.00, RowTotalRoundingCalculator::round(25.00, 100.00));

        // 50.00 with increment 100.00 rounds to 100.00
        $this->assertEquals(100.00, RowTotalRoundingCalculator::round(50.00, 100.00));
    }
}
