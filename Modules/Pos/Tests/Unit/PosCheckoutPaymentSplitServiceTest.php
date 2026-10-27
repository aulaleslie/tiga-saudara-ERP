<?php

namespace Modules\Pos\Tests\Unit;

use Modules\Pos\Services\PosCheckoutPaymentSplitService;
use Tests\TestCase;

class PosCheckoutPaymentSplitServiceTest extends TestCase
{
    public function test_allocate_uses_deterministic_largest_remainder_by_split_key(): void
    {
        $service = new PosCheckoutPaymentSplitService();

        $allocations = $service->allocate([
            ['split_key' => 'B', 'grand_total' => 100.0],
            ['split_key' => 'A', 'grand_total' => 100.0],
            ['split_key' => 'C', 'grand_total' => 100.0],
        ], 100.0);

        $this->assertSame(33.34, $allocations['A']);
        $this->assertSame(33.33, $allocations['B']);
        $this->assertSame(33.33, $allocations['C']);
        $this->assertSame(100.0, round(array_sum($allocations), 2));
    }

    public function test_allocate_returns_zeroes_when_total_is_zero(): void
    {
        $service = new PosCheckoutPaymentSplitService();

        $allocations = $service->allocate([
            ['split_key' => 'A', 'grand_total' => 10.0],
            ['split_key' => 'B', 'grand_total' => 20.0],
        ], 0.0);

        $this->assertSame(0.0, $allocations['A']);
        $this->assertSame(0.0, $allocations['B']);
    }

    public function test_allocate_handles_logged_large_production_kasbon_case(): void
    {
        $service = new PosCheckoutPaymentSplitService();

        $allocations = $service->allocate([
            ['split_key' => 'group_1', 'grand_total' => 39385000.0],
            ['split_key' => 'group_2', 'grand_total' => 13600000.0],
        ], 30000000.0);

        $this->assertSame(22299707.46, $allocations['group_1']);
        $this->assertSame(7700292.54, $allocations['group_2']);
        $this->assertSame(30000000.0, round(array_sum($allocations), 2));
    }

    public function test_allocate_preserves_exact_shares_and_full_payment(): void
    {
        $service = new PosCheckoutPaymentSplitService();

        $allocations = $service->allocate([
            ['split_key' => 'A', 'grand_total' => 50000.0],
            ['split_key' => 'B', 'grand_total' => 50000.0],
        ], 100000.0);

        $this->assertSame(50000.0, $allocations['A']);
        $this->assertSame(50000.0, $allocations['B']);
        $this->assertSame(100000.0, round(array_sum($allocations), 2));
    }

    public function test_allocate_handles_small_and_single_minor_unit_amounts(): void
    {
        $service = new PosCheckoutPaymentSplitService();

        $allocations = $service->allocate([
            ['split_key' => 'A', 'grand_total' => 100.0],
            ['split_key' => 'B', 'grand_total' => 200.0],
        ], 0.01);

        $this->assertSame(0.0, $allocations['A']);
        $this->assertSame(0.01, $allocations['B']);
        $this->assertSame(0.01, round(array_sum($allocations), 2));
    }

    public function test_allocate_preserves_deterministic_rounding_ties(): void
    {
        $service = new PosCheckoutPaymentSplitService();

        // 4 equal groups, 0.02 total -> each gets 0 base, remainder is 2 minor units
        // Since remainders are equal, the first two sorted split keys (A and B) should receive 0.01 each
        $allocations = $service->allocate([
            ['split_key' => 'D', 'grand_total' => 1000.0],
            ['split_key' => 'B', 'grand_total' => 1000.0],
            ['split_key' => 'A', 'grand_total' => 1000.0],
            ['split_key' => 'C', 'grand_total' => 1000.0],
        ], 0.02);

        $this->assertSame(0.01, $allocations['A']);
        $this->assertSame(0.01, $allocations['B']);
        $this->assertSame(0.0, $allocations['C']);
        $this->assertSame(0.0, $allocations['D']);
        $this->assertSame(0.02, round(array_sum($allocations), 2));
    }
}
