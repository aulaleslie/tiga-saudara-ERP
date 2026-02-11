<?php

namespace Modules\Sale\Tests\Unit;

use Modules\Sale\Services\PosAllocationEngine;
use Tests\TestCase;

class PosAllocationEngineTest extends TestCase
{
    public function test_allocates_non_tax_globally_before_tax_across_locations(): void
    {
        $engine = app(PosAllocationEngine::class);

        $result = $engine->allocate(
            requestedQuantity: 3,
            locationPriority: [1, 2],
            stocksByLocation: [
                1 => ['available_non_tax' => 1, 'available_tax' => 10],
                2 => ['available_non_tax' => 10, 'available_tax' => 0],
            ],
            existingAllocations: []
        );

        $this->assertSame(0, $result['remaining']);
        $this->assertSame(3, $result['allocated_non_tax']);
        $this->assertSame(0, $result['allocated_tax']);

        $allocations = collect($result['allocations'])->keyBy('location_id');
        $this->assertSame(1, (int) $allocations[1]['allocated_non_tax']);
        $this->assertSame(2, (int) $allocations[2]['allocated_non_tax']);
        $this->assertSame(0, (int) $allocations[1]['allocated_tax']);
    }

    public function test_respects_existing_allocations_and_only_fills_missing_quantity(): void
    {
        $engine = app(PosAllocationEngine::class);

        $result = $engine->allocate(
            requestedQuantity: 5,
            locationPriority: [1, 2],
            stocksByLocation: [
                1 => ['available_non_tax' => 3, 'available_tax' => 2],
                2 => ['available_non_tax' => 4, 'available_tax' => 1],
            ],
            existingAllocations: [
                ['location_id' => 1, 'allocated_non_tax' => 2, 'allocated_tax' => 0],
            ]
        );

        $this->assertSame(0, $result['remaining']);
        $this->assertSame(5, $result['allocated_non_tax'] + $result['allocated_tax']);

        $allocations = collect($result['allocations'])->keyBy('location_id');
        $this->assertGreaterThanOrEqual(2, (int) $allocations[1]['allocated_non_tax']);
    }

    public function test_returns_remaining_when_total_stock_is_insufficient(): void
    {
        $engine = app(PosAllocationEngine::class);

        $result = $engine->allocate(
            requestedQuantity: 10,
            locationPriority: [1, 2],
            stocksByLocation: [
                1 => ['available_non_tax' => 2, 'available_tax' => 1],
                2 => ['available_non_tax' => 3, 'available_tax' => 1],
            ],
            existingAllocations: []
        );

        $this->assertSame(3, $result['remaining']);
        $this->assertSame(7, $result['allocated_non_tax'] + $result['allocated_tax']);
    }
}
