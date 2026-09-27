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
}
