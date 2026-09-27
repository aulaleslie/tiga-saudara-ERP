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
        $this->assertSame(0, $result['breakdown']['box_count']);
        $this->assertSame(5, $result['breakdown']['loose_count']);
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
        $this->assertSame(0, $result['breakdown']['box_count']);
        $this->assertSame(6, $result['breakdown']['loose_count']);
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
        $this->assertSame(0, $result['breakdown']['box_count']);
        $this->assertSame(6, $result['breakdown']['loose_count']);
        $this->assertSame('tier_2', $result['breakdown']['tier']);
    }

    public function test_qty_5_wholesaler_customer_string_bypasses_cheaper_box_price(): void
    {
        // Wholesaler tier_1_price is 4400000; box_price is 21000000 (which is 4.2M/unit).
        // Wholesaler bypasses conversion price, resulting in 5 × 4.4M = 22000000.
        $pricingBasis = [
            'factor' => 5,
            'box_price' => 21000000,
            'base_price' => 4500000,
            'tier_1_price' => 4400000,
            'tier_2_price' => 4200000,
        ];

        $result = $this->service->price(5, 'WHOLESALER', $pricingBasis);

        $this->assertSame(22000000, $result['line_total_minor']);
        $this->assertSame(4400000, $result['blended_unit_price']);
        $this->assertSame(0, $result['breakdown']['box_count']);
        $this->assertSame(5, $result['breakdown']['loose_count']);
        $this->assertSame('tier_1', $result['breakdown']['tier']);
        $this->assertFalse($result['breakdown']['is_box_cheaper']);
    }

    public function test_reseller_decimal_tier_price_bypasses_conversion(): void
    {
        // Reseller tier_2_price is 658333 minor units (6583.33); factor 12, box_price 8500000 minor (85000). Qty 12.
        // Reseller bypasses box_price (85000) and charges 12 × 658333 = 7899996 minor (78999.96).
        $pricingBasis = [
            'factor' => 12,
            'box_price' => 8500000,
            'base_price' => 800000,
            'tier_1_price' => 750000,
            'tier_2_price' => 658333,
        ];

        $result = $this->service->price(12, 'RESELLER', $pricingBasis);

        $this->assertSame(7899996, $result['line_total_minor']);
        $this->assertSame('tier_2', $result['breakdown']['tier']);
        $this->assertFalse($result['breakdown']['is_box_cheaper']);
    }

    public function test_tier_price_fallback_still_bypasses_conversion_pricing(): void
    {
        // Wholesaler tier_1_price is missing (0), so fallback resolves to base_price 800000 (8000.00).
        // Box price is 8500000 (85000.00) which would be cheaper for 12 units (12 × 8000 = 96000),
        // but tier customer fallback STILL bypasses box pricing and charges 12 × 800000 = 9600000.
        $pricingBasis = [
            'factor' => 12,
            'box_price' => 8500000,
            'base_price' => 800000,
            'tier_1_price' => 0,
            'tier_2_price' => 0,
        ];

        $result = $this->service->price(12, 'WHOLESALER', $pricingBasis);

        $this->assertSame(9600000, $result['line_total_minor']);
        $this->assertSame(800000, $result['blended_unit_price']);
        $this->assertFalse($result['breakdown']['is_box_cheaper']);
    }
}
