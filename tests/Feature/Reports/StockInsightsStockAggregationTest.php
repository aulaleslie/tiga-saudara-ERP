<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\StockInsightsQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class StockInsightsStockAggregationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_aggregates_stock_from_location_to_business_to_global_and_excludes_inactive_locations()
    {
        $biz1 = Setting::factory()->create(['company_name' => 'Bisnis Alpha']);
        $biz2 = Setting::factory()->create(['company_name' => 'Bisnis Beta']);

        // Biz 1: 2 active locations
        $loc1A = Location::create(['setting_id' => $biz1->id, 'name' => 'Gudang 1A', 'is_active' => true]);
        $loc1B = Location::create(['setting_id' => $biz1->id, 'name' => 'Toko 1B', 'is_active' => true]);

        // Biz 2: 1 active location, 1 inactive location
        $loc2A = Location::create(['setting_id' => $biz2->id, 'name' => 'Gudang 2A', 'is_active' => true]);
        $loc2Inactive = Location::create(['setting_id' => $biz2->id, 'name' => 'Gudang Tutup', 'is_active' => false]);

        // Stock-managed active product
        $product = Product::create([
            'setting_id' => $biz1->id,
            'product_name' => 'Semen Tiga Roda',
            'product_code' => 'SMN-001',
            'product_cost' => 50000,
            'product_price' => 65000,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Non-stock managed product (service)
        $service = Product::create([
            'setting_id' => $biz1->id,
            'product_name' => 'Jasa Antar',
            'product_code' => 'JSA-001',
            'product_cost' => 0,
            'product_price' => 15000,
            'stock_managed' => false,
            'is_active' => true,
        ]);

        // Product stock in loc1A: 7 Good Tax, 3 Good Non-Tax, 2 Broken Tax, 1 Broken Non-Tax
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc1A->id,
            'quantity' => 10,
            'quantity_tax' => 7,
            'quantity_non_tax' => 3,
            'broken_quantity' => 3,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 1,
        ]);

        // Product stock in loc1B: 5 Good Tax, 0 Good Non-Tax, 0 Broken Tax, 0 Broken Non-Tax
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc1B->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Product stock in loc2A: 0 Good Tax, 10 Good Non-Tax, 1 Broken Tax, 2 Broken Non-Tax
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc2A->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 3,
            'broken_quantity_tax' => 1,
            'broken_quantity_non_tax' => 2,
        ]);

        // Product stock in inactive location: should be completely ignored!
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc2Inactive->id,
            'quantity' => 1998,
            'quantity_tax' => 999,
            'quantity_non_tax' => 999,
            'broken_quantity' => 1998,
            'broken_quantity_tax' => 999,
            'broken_quantity_non_tax' => 999,
        ]);

        $serviceInstance = new StockInsightsQueryService();
        $matrix = $serviceInstance->getStockMatrix([$product->id]);

        $this->assertArrayHasKey($product->id, $matrix);
        $prodMatrix = $matrix[$product->id];

        // Global reconciliation:
        // loc1A Good: 7 + 3 = 10, Broken: 2 + 1 = 3
        // loc1B Good: 5 + 0 = 5, Broken: 0 + 0 = 0
        // loc2A Good: 0 + 10 = 10, Broken: 1 + 2 = 3
        // Inactive excluded!
        // Total Global Good = 10 + 5 + 10 = 25
        // Total Global Broken = 3 + 0 + 3 = 6
        $this->assertEquals(25.0, $prodMatrix['global']->totalGood);
        $this->assertEquals(6.0, $prodMatrix['global']->totalBroken);
        $this->assertEquals(12.0, $prodMatrix['global']->taxGood);
        $this->assertEquals(13.0, $prodMatrix['global']->nonTaxGood);
        $this->assertEquals(3.0, $prodMatrix['global']->taxBroken);
        $this->assertEquals(3.0, $prodMatrix['global']->nonTaxBroken);

        // Bisnis 1 subtotal:
        // Good = 15, Broken = 3
        $this->assertArrayHasKey($biz1->id, $prodMatrix['businesses']);
        $biz1Stock = $prodMatrix['businesses'][$biz1->id]['stock'];
        $this->assertEquals(15.0, $biz1Stock->totalGood);
        $this->assertEquals(3.0, $biz1Stock->totalBroken);

        // Bisnis 2 subtotal:
        // Good = 10, Broken = 3
        $this->assertArrayHasKey($biz2->id, $prodMatrix['businesses']);
        $biz2Stock = $prodMatrix['businesses'][$biz2->id]['stock'];
        $this->assertEquals(10.0, $biz2Stock->totalGood);
        $this->assertEquals(3.0, $biz2Stock->totalBroken);

        // Inactive location must not be in locations
        $this->assertArrayNotHasKey($loc2Inactive->id, $prodMatrix['businesses'][$biz2->id]['locations']);
    }
}
