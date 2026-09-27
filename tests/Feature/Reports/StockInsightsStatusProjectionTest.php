<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\StockInsightsFilterData;
use App\Services\Reports\StockInsightsQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class StockInsightsStatusProjectionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_accurately_projects_and_counts_statuses_for_equality_zero_negative_minimum_unset_and_fractional_stock()
    {
        $setting = Setting::factory()->create();
        $loc = Location::create(['setting_id' => $setting->id, 'name' => 'Main Loc', 'is_active' => true]);

        // Product 1: Zero stock, minimum alert = 5 -> Stok Habis
        $p1 = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Product Zero Stock',
            'product_code' => 'P-001',
            'product_cost' => 100,
            'product_price' => 200,
            'product_stock_alert' => 5,
            'stock_managed' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $p1->id,
            'location_id' => $loc->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Product 2: Negative stock (-2), minimum alert = 10 -> Stok Habis
        $p2 = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Product Negative Stock',
            'product_code' => 'P-002',
            'product_cost' => 100,
            'product_price' => 200,
            'product_stock_alert' => 10,
            'stock_managed' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $p2->id,
            'location_id' => $loc->id,
            'quantity' => -2,
            'quantity_tax' => -2,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Product 3: Exactly equal to minimum (alert = 5, stock = 5) -> Perlu Dibeli Lagi
        $p3 = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Product Exact Alert',
            'product_code' => 'P-003',
            'product_cost' => 100,
            'product_price' => 200,
            'product_stock_alert' => 5,
            'stock_managed' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $p3->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_tax' => 3,
            'quantity_non_tax' => 2,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Product 4: Fractional stock below minimum (alert = 5, stock = 4.5) -> Perlu Dibeli Lagi
        $p4 = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Product Fractional Stock Below Alert',
            'product_code' => 'P-004',
            'product_cost' => 100,
            'product_price' => 200,
            'product_stock_alert' => 5,
            'stock_managed' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $p4->id,
            'location_id' => $loc->id,
            'quantity' => 4.5,
            'quantity_tax' => 2.5,
            'quantity_non_tax' => 2.0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Product 5: Minimum unset (alert = 0, stock = 8) -> Batas Minimum Belum Diatur
        $p5 = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Product Zero Alert',
            'product_code' => 'P-005',
            'product_cost' => 100,
            'product_price' => 200,
            'product_stock_alert' => 0,
            'stock_managed' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $p5->id,
            'location_id' => $loc->id,
            'quantity' => 8,
            'quantity_tax' => 4,
            'quantity_non_tax' => 4,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Product 6: Sufficient stock (alert = 5, stock = 10) -> No alert
        $p6 = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Product Sufficient Stock',
            'product_code' => 'P-006',
            'product_cost' => 100,
            'product_price' => 200,
            'product_stock_alert' => 5,
            'stock_managed' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $p6->id,
            'location_id' => $loc->id,
            'quantity' => 10,
            'quantity_tax' => 5,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $service = new StockInsightsQueryService();
        $counts = $service->getAttentionCounts();

        // P1 (zero) and P2 (negative) = 2 out of stock
        $this->assertEquals(2, $counts['out_of_stock']);

        // P3 (exact 5 <= 5) and P4 (4.5 <= 5) = 2 reorder required
        $this->assertEquals(2, $counts['reorder_required']);

        // P5 (alert = 0) = 1 minimum unset
        $this->assertEquals(1, $counts['minimum_unset']);
    }
}
