<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Unit;

use Modules\Adjustment\Services\StockOpnameAllocationPlanner;
use PHPUnit\Framework\TestCase;

class StockOpnameAllocationPlannerTest extends TestCase
{
    private StockOpnameAllocationPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new StockOpnameAllocationPlanner();
    }

    /** @test */
    public function zero_difference_produces_no_changes(): void
    {
        $locations = [
            ['location_id' => 1, 'setting_id' => 1, 'is_pkp' => true, 'stock' => 10],
            ['location_id' => 2, 'setting_id' => 2, 'is_pkp' => false, 'stock' => 5],
        ];

        $plan = $this->planner->plan($locations, 0, 'good');

        $this->assertEquals(0, $plan['difference']);
        $this->assertEquals('none', $plan['type']);
        $this->assertEquals(0, $plan['unallocated']);
        $this->assertEquals(0, $plan['steps'][0]['delta']);
        $this->assertEquals(10, $plan['steps'][0]['after_stock']);
        $this->assertEquals(0, $plan['steps'][1]['delta']);
        $this->assertEquals(5, $plan['steps'][1]['after_stock']);
    }

    /** @test */
    public function shortage_prioritizes_non_pkp_before_pkp(): void
    {
        // Loc 1: PKP with stock 100
        // Loc 2: Non-PKP with stock 10
        // Loc 3: Non-PKP with stock 20
        $locations = [
            ['location_id' => 1, 'setting_id' => 1, 'is_pkp' => true, 'stock' => 100],
            ['location_id' => 2, 'setting_id' => 2, 'is_pkp' => false, 'stock' => 10],
            ['location_id' => 3, 'setting_id' => 2, 'is_pkp' => false, 'stock' => 20],
        ];

        // Shortage of -25:
        // Non-PKP order: Loc 3 (stock 20) -> deduct 20, Loc 2 (stock 10) -> deduct 5.
        // PKP Loc 1 (stock 100) should NOT be touched!
        $plan = $this->planner->plan($locations, -25, 'good');

        $this->assertEquals('shortage', $plan['type']);
        $this->assertEquals(0, $plan['unallocated']);

        $stepsByLoc = collect($plan['steps'])->keyBy('location_id');

        $this->assertEquals(0, $stepsByLoc[1]['delta'], 'PKP location must not be deducted while non-PKP stock remains');
        $this->assertEquals(100, $stepsByLoc[1]['after_stock']);

        $this->assertEquals(-20, $stepsByLoc[3]['delta'], 'Non-PKP with highest stock (20) must be deducted first');
        $this->assertEquals(0, $stepsByLoc[3]['after_stock']);

        $this->assertEquals(-5, $stepsByLoc[2]['delta'], 'Remaining 5 shortage must be deducted from next non-PKP');
        $this->assertEquals(5, $stepsByLoc[2]['after_stock']);
    }

    /** @test */
    public function shortage_waterfalls_into_pkp_when_non_pkp_is_exhausted(): void
    {
        $locations = [
            ['location_id' => 1, 'setting_id' => 1, 'is_pkp' => true, 'stock' => 50],
            ['location_id' => 2, 'setting_id' => 2, 'is_pkp' => false, 'stock' => 10],
        ];

        // Shortage of -30:
        // Loc 2 (Non-PKP, 10) -> deduct 10 (exhausted).
        // Loc 1 (PKP, 50) -> deduct 20.
        $plan = $this->planner->plan($locations, -30, 'good');

        $stepsByLoc = collect($plan['steps'])->keyBy('location_id');

        $this->assertEquals(-10, $stepsByLoc[2]['delta']);
        $this->assertEquals(0, $stepsByLoc[2]['after_stock']);

        $this->assertEquals(-20, $stepsByLoc[1]['delta']);
        $this->assertEquals(30, $stepsByLoc[1]['after_stock']);
    }

    /** @test */
    public function surplus_assigns_entire_amount_to_non_pkp_with_lowest_stock(): void
    {
        // Loc 1: PKP with stock 0
        // Loc 2: Non-PKP with stock 10
        // Loc 3: Non-PKP with stock 2
        $locations = [
            ['location_id' => 1, 'setting_id' => 1, 'is_pkp' => true, 'stock' => 0],
            ['location_id' => 2, 'setting_id' => 2, 'is_pkp' => false, 'stock' => 10],
            ['location_id' => 3, 'setting_id' => 2, 'is_pkp' => false, 'stock' => 2],
        ];

        // Surplus +15:
        // Non-PKP with lowest stock is Loc 3 (stock 2).
        // Loc 3 gets all +15.
        $plan = $this->planner->plan($locations, 15, 'good');

        $this->assertEquals('surplus', $plan['type']);
        $this->assertEquals(0, $plan['unallocated']);

        $stepsByLoc = collect($plan['steps'])->keyBy('location_id');

        $this->assertEquals(15, $stepsByLoc[3]['delta']);
        $this->assertEquals(17, $stepsByLoc[3]['after_stock']);

        $this->assertEquals(0, $stepsByLoc[2]['delta']);
        $this->assertEquals(10, $stepsByLoc[2]['after_stock']);

        $this->assertEquals(0, $stepsByLoc[1]['delta']);
        $this->assertEquals(0, $stepsByLoc[1]['after_stock']);
    }

    /** @test */
    public function ties_are_broken_by_location_id_asc(): void
    {
        // Loc 10: Non-PKP, stock 5
        // Loc 5: Non-PKP, stock 5
        $locations = [
            ['location_id' => 10, 'setting_id' => 1, 'is_pkp' => false, 'stock' => 5],
            ['location_id' => 5, 'setting_id' => 1, 'is_pkp' => false, 'stock' => 5],
        ];

        // Surplus +7: Loc 5 has lower location_id, gets the entire surplus
        $surplusPlan = $this->planner->plan($locations, 7, 'good');
        $surplusSteps = collect($surplusPlan['steps'])->keyBy('location_id');
        $this->assertEquals(7, $surplusSteps[5]['delta']);
        $this->assertEquals(0, $surplusSteps[10]['delta']);

        // Shortage -3: Loc 5 has lower location_id, is deducted first in tie
        $shortagePlan = $this->planner->plan($locations, -3, 'good');
        $shortageSteps = collect($shortagePlan['steps'])->keyBy('location_id');
        $this->assertEquals(-3, $shortageSteps[5]['delta']);
        $this->assertEquals(2, $shortageSteps[5]['after_stock']);
        $this->assertEquals(0, $shortageSteps[10]['delta']);
    }

    /** @test */
    public function all_pkp_locations_follow_stock_ordering(): void
    {
        $locations = [
            ['location_id' => 1, 'setting_id' => 1, 'is_pkp' => true, 'stock' => 20],
            ['location_id' => 2, 'setting_id' => 1, 'is_pkp' => true, 'stock' => 10],
        ];

        // Shortage -15: highest stock (Loc 1, 20) deducted first
        $plan = $this->planner->plan($locations, -15, 'good');
        $steps = collect($plan['steps'])->keyBy('location_id');
        $this->assertEquals(-15, $steps[1]['delta']);
        $this->assertEquals(5, $steps[1]['after_stock']);
        $this->assertEquals(0, $steps[2]['delta']);

        // Surplus +8: lowest stock (Loc 2, 10) gets surplus
        $surplusPlan = $this->planner->plan($locations, 8, 'good');
        $surplusSteps = collect($surplusPlan['steps'])->keyBy('location_id');
        $this->assertEquals(8, $surplusSteps[2]['delta']);
        $this->assertEquals(18, $surplusSteps[2]['after_stock']);
        $this->assertEquals(0, $surplusSteps[1]['delta']);
    }

    /** @test */
    public function waterfall_exhaustion_does_not_result_in_negative_stock(): void
    {
        $locations = [
            ['location_id' => 1, 'setting_id' => 1, 'is_pkp' => false, 'stock' => 3],
            ['location_id' => 2, 'setting_id' => 1, 'is_pkp' => true, 'stock' => 2],
        ];

        // Shortage of -10 with only 5 total stock available
        $plan = $this->planner->plan($locations, -10, 'good');

        $this->assertEquals(5, $plan['unallocated']);
        $steps = collect($plan['steps'])->keyBy('location_id');

        $this->assertEquals(-3, $steps[1]['delta']);
        $this->assertEquals(0, $steps[1]['after_stock']);

        $this->assertEquals(-2, $steps[2]['delta']);
        $this->assertEquals(0, $steps[2]['after_stock']);
    }
}
