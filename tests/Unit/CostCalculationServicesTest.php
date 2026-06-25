<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Purchase\Services\PurchaseCostHelper;
use Modules\Product\Services\ProductAveragePriceSynchronizer;
use Modules\Sale\Services\SalesCostSnapshotService;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\Sale;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Product\Entities\ProductPrice;
use Mockery;

class CostCalculationServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_dpp_cost_helper_calculates_correctly()
    {
        $cost = PurchaseCostHelper::calculateUnitCost(110000, 10000, 5000, 2);
        $this->assertEquals(47500.0, $cost);
        
        // Zero qty
        $cost = PurchaseCostHelper::calculateUnitCost(110000, 10000, 5000, 0);
        $this->assertEquals(0.0, $cost);
        
        // Decimal qty
        $cost = PurchaseCostHelper::calculateUnitCost(1000, 0, 0, 0.5);
        $this->assertEquals(2000.0, $cost);
    }

    public function test_global_average_synchronization()
    {
        $setting1 = Setting::factory()->create();
        $setting2 = Setting::factory()->create();
        
        $productId = \Illuminate\Support\Facades\DB::table('products')->insertGetId([
            'category_id' => null,
            'product_name' => 'Test',
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $sync = new ProductAveragePriceSynchronizer();
        $sync->syncAveragePurchasePrice($productId, 12500.50);
        
        $prices = ProductPrice::where('product_id', $productId)->get();
        $this->assertCount(2, $prices);
        $this->assertEquals(12500.50, $prices->firstWhere('setting_id', $setting1->id)->average_purchase_price);
        $this->assertEquals(12500.50, $prices->firstWhere('setting_id', $setting2->id)->average_purchase_price);
    }

    public function test_sales_cost_snapshot_missing_price_zero_cost_fallback()
    {
        $product = Mockery::mock(Product::class)->makePartial();
        $product->stock_managed = true;
        $product->shouldReceive('averagePurchasePrice')->andReturn(null);

        $saleDetail = new SaleDetails();
        $saleDetail->quantity = 2;
        $saleDetail->setRelation('product', $product);

        $service = new SalesCostSnapshotService();
        $service->snapshotSaleDetailCost($saleDetail);

        $this->assertEquals(0, $saleDetail->cost_unit_snapshot);
        $this->assertEquals(0, $saleDetail->cost_total_snapshot);
        $this->assertEquals(SalesCostSnapshotService::SOURCE_MISSING_AVERAGE_PRICE, $saleDetail->cost_snapshot_source);
        $this->assertNotNull($saleDetail->cost_snapshot_at);
    }

    public function test_sales_cost_snapshot_non_stock_managed_fallback()
    {
        $product = Mockery::mock(Product::class)->makePartial();
        $product->stock_managed = false;

        $saleDetail = new SaleDetails();
        $saleDetail->quantity = 2;
        $saleDetail->setRelation('product', $product);

        $service = new SalesCostSnapshotService();
        $service->snapshotSaleDetailCost($saleDetail);

        $this->assertEquals(0, $saleDetail->cost_unit_snapshot);
        $this->assertEquals(0, $saleDetail->cost_total_snapshot);
        $this->assertEquals(SalesCostSnapshotService::SOURCE_NON_STOCK_MANAGED, $saleDetail->cost_snapshot_source);
    }

    public function test_sales_cost_snapshot_decimal_quantity_rounding()
    {
        $product = Mockery::mock(Product::class)->makePartial();
        $product->stock_managed = true;
        $product->shouldReceive('averagePurchasePrice')->andReturn('12500.333333');

        $saleDetail = new SaleDetails();
        $saleDetail->quantity = 1.5; // Decimal qty
        $saleDetail->setRelation('product', $product);

        $service = new SalesCostSnapshotService();
        $service->snapshotSaleDetailCost($saleDetail);

        $this->assertEquals(12500.333333, $saleDetail->cost_unit_snapshot);
        // 12500.333333 * 1.5 = 18750.50
        $this->assertEquals(18750.50, $saleDetail->cost_total_snapshot);
        $this->assertEquals(SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE, $saleDetail->cost_snapshot_source);
    }
}
