<?php

namespace Modules\Pos\Tests\Unit;

use Modules\Pos\Services\PackedLinePricingService;
use PHPUnit\Framework\TestCase;

class PackedLinePricingServiceTest extends TestCase
{
    private PackedLinePricingService $service;

    protected function setUp(): void
    {
        $this->service = new PackedLinePricingService();
    }

    public function test_qty_3_non_tier_loose_only(): void
    {
        $pricingBasis = [
            'factor' => 5,
            'box_price' => 21000000,
            'base_price' => 4500000,
            'tier_1_price' => 4400000,
            'tier_2_price' => 4200000,
        ];

        $result = $this->service->price(3, null, $pricingBasis);

        $this->assertSame(13500000, $result['line_total_minor']);
        $this->assertSame(4500000, $result['blended_unit_price']);
        $this->assertSame(0, $result['breakdown']['box_count']);
        $this->assertSame(3, $result['breakdown']['loose_count']);
    }

    public function test_qty_5_non_tier_one_box(): void
    {
        $pricingBasis = [
            'factor' => 5,
            'box_price' => 21000000,
            'base_price' => 4500000,
            'tier_1_price' => 4400000,
            'tier_2_price' => 4200000,
        ];

        $result = $this->service->price(5, null, $pricingBasis);

        $this->assertSame(21000000, $result['line_total_minor']);
        $this->assertSame(4200000, $result['blended_unit_price']);
        $this->assertSame(1, $result['breakdown']['box_count']);
        $this->assertSame(0, $result['breakdown']['loose_count']);
    }

    public function test_qty_6_non_tier_one_box_one_loose(): void
    {
        $pricingBasis = [
            'factor' => 5,
            'box_price' => 21000000,
            'base_price' => 4500000,
            'tier_1_price' => 4400000,
            'tier_2_price' => 4200000,
        ];

        $result = $this->service->price(6, null, $pricingBasis);

        $this->assertSame(25500000, $result['line_total_minor']);
        $this->assertSame(4250000, $result['blended_unit_price']);
        $this->assertSame(1, $result['breakdown']['box_count']);
        $this->assertSame(1, $result['breakdown']['loose_count']);
    }

    public function test_qty_3_reseller_loose_only(): void
    {
        $pricingBasis = [
            'factor' => 5,
            'box_price' => 21000000,
            'base_price' => 4500000,
            'tier_1_price' => 4400000,
            'tier_2_price' => 4200000,
        ];

        $result = $this->service->price(3, 'tier_2', $pricingBasis);

        $this->assertSame(12600000, $result['line_total_minor']);
        $this->assertSame(4200000, $result['blended_unit_price']);
        $this->assertSame(0, $result['breakdown']['box_count']);
        $this->assertSame(3, $result['breakdown']['loose_count']);
    }

    public function test_qty_5_reseller_one_box_tier_matches(): void
    {
        $pricingBasis = [
            'factor' => 5,
            'box_price' => 21000000,
            'base_price' => 4500000,
            'tier_1_price' => 4400000,
            'tier_2_price' => 4200000,
        ];

        $result = $this->service->price(5, 'tier_2', $pricingBasis);

        $this->assertSame(21000000, $result['line_total_minor']);
        $this->assertSame(4200000, $result['blended_unit_price']);
        $this->assertSame(1, $result['breakdown']['box_count']);
        $this->assertSame(0, $result['breakdown']['loose_count']);
    }

    public function test_qty_6_reseller_one_box_one_loose(): void
    {
        $pricingBasis = [
            'factor' => 5,
            'box_price' => 21000000,
            'base_price' => 4500000,
            'tier_1_price' => 4400000,
            'tier_2_price' => 4200000,
        ];

        $result = $this->service->price(6, 'tier_2', $pricingBasis);

        $this->assertSame(25200000, $result['line_total_minor']);
        $this->assertSame(4200000, $result['blended_unit_price']);
        $this->assertSame(1, $result['breakdown']['box_count']);
        $this->assertSame(1, $result['breakdown']['loose_count']);
    }

    public function test_qty_6_reseller_customer_string_one_box_one_loose(): void
    {
        // Test with customer tier string 'RESELLER' instead of 'tier_2'
        $pricingBasis = [
            'factor' => 5,
            'box_price' => 21000000,
            'base_price' => 4500000,
            'tier_1_price' => 4400000,
            'tier_2_price' => 4200000,
        ];

        $result = $this->service->price(6, 'RESELLER', $pricingBasis);

        $this->assertSame(25200000, $result['line_total_minor']);
        $this->assertSame(4200000, $result['blended_unit_price']);
        $this->assertSame(1, $result['breakdown']['box_count']);
        $this->assertSame(1, $result['breakdown']['loose_count']);
        $this->assertSame('tier_2', $result['breakdown']['tier']);
    }

    public function test_qty_5_wholesaler_customer_string_one_box(): void
    {
        // Test with customer tier string 'WHOLESALER' instead of 'tier_1'
        $pricingBasis = [
            'factor' => 5,
            'box_price' => 21000000,
            'base_price' => 4500000,
            'tier_1_price' => 4400000,
            'tier_2_price' => 4200000,
        ];

        $result = $this->service->price(5, 'WHOLESALER', $pricingBasis);

        $this->assertSame(21000000, $result['line_total_minor']);
        $this->assertSame(4200000, $result['blended_unit_price']);
        $this->assertSame(1, $result['breakdown']['box_count']);
        $this->assertSame(0, $result['breakdown']['loose_count']);
        $this->assertSame('tier_1', $result['breakdown']['tier']);
    }
}
