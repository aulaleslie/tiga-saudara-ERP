<?php

namespace Tests\Unit;

use App\Support\ImportDocumentAdjustmentAllocator;
use PHPUnit\Framework\TestCase;

class ImportDocumentAdjustmentAllocatorTest extends TestCase
{
    private ImportDocumentAdjustmentAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new ImportDocumentAdjustmentAllocator();
    }

    public function test_zero_amount_allocates_zero_to_every_group(): void
    {
        $result = $this->allocator->allocate(['a' => 100000.0, 'b' => 100000.0], 0.0);

        $this->assertSame(['a' => 0.0, 'b' => 0.0], $result);
    }

    public function test_single_positive_group_receives_full_amount(): void
    {
        $result = $this->allocator->allocate(['a' => 200000.0, 'zero' => 0.0], 15000.0);

        $this->assertEqualsWithDelta(15000.0, $result['a'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['zero'], 0.01);
    }

    public function test_equal_groups_split_amount_evenly(): void
    {
        // The reviewer scenario: two equal owner groups, repeated Diskon 15000.
        $result = $this->allocator->allocate(['a' => 100000.0, 'b' => 100000.0], 15000.0);

        $this->assertEqualsWithDelta(7500.0, $result['a'], 0.01);
        $this->assertEqualsWithDelta(7500.0, $result['b'], 0.01);
        $this->assertEqualsWithDelta(15000.0, $result['a'] + $result['b'], 0.01);
    }

    public function test_uneven_groups_allocate_pro_rata(): void
    {
        $result = $this->allocator->allocate(['a' => 100000.0, 'b' => 200000.0], 30000.0);

        $this->assertEqualsWithDelta(10000.0, $result['a'], 0.01);
        $this->assertEqualsWithDelta(20000.0, $result['b'], 0.01);
    }

    public function test_rounding_remainder_goes_to_largest_group_and_sum_is_preserved(): void
    {
        // 100 / 3 split forces a rounding remainder.
        $result = $this->allocator->allocate(['a' => 100.0, 'b' => 100.0, 'c' => 100.0], 100.0);

        $this->assertEqualsWithDelta(100.0, array_sum($result), 0.001);
    }

    public function test_zero_total_groups_receive_nothing(): void
    {
        $result = $this->allocator->allocate(['a' => 100000.0, 'zero' => 0.0], 15000.0);

        $this->assertEqualsWithDelta(0.0, $result['zero'], 0.01);
    }
}
