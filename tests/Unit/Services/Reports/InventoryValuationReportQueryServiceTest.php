<?php

namespace Tests\Unit\Services\Reports;

use App\Services\Reports\InventoryValuationReportFilterData;
use App\Services\Reports\InventoryValuationReportQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class InventoryValuationReportQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private InventoryValuationReportQueryService $service;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $user = \App\Models\User::factory()->create();
        $this->setting = Setting::factory()->create();
        $this->category = Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C1', 'category_name' => 'Cat1', 'created_by' => $user->id]);
        \Modules\Setting\Entities\Location::create(["id" => 1, "setting_id" => $this->setting->id, "name" => "Main"]);
        $this->service = new InventoryValuationReportQueryService();
    }

    private function createProduct(array $overrides = [])
    {
        return Product::create(array_merge([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST',
            'product_price' => 100,
            'product_cost' => 100,
            'stock_managed' => true,
            'average_purchase_price' => 0,
        ], $overrides));
    }

    private function createTransaction(array $overrides = [])
    {
        $tx = Transaction::create(array_merge([
            'setting_id' => $this->setting->id,
            'product_id' => 1,
            'location_id' => 1,
            'type' => 'BUY',
            'current_quantity' => 0,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 0,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'quantity' => 10,
            'previous_quantity' => 0,
            'after_quantity' => 10,
        ], $overrides));

        if (isset($overrides['created_at'])) {
            $tx->created_at = $overrides['created_at'];
            $tx->save();
        }

        return $tx;
    }

    public function test_it_reflects_pre_range_activity_in_opening_balance()
    {
        $product = $this->createProduct(['average_purchase_price' => 1000]);

        $this->createTransaction([
            'product_id' => $product->id,
            'type' => 'BUY',
            'quantity' => 10,
            'after_quantity' => 10,
            'reason' => 'Init #BUY-01',
            'created_at' => Carbon::parse('2023-10-01 10:00:00')
        ]);

        $filters = new InventoryValuationReportFilterData(
            Carbon::parse('2023-10-10 00:00:00'),
            Carbon::parse('2023-10-20 23:59:59')
        );

        $result = $this->service->getReport($filters, $this->setting->id);

        $this->assertCount(1, $result['allRows']);
        $group = $result['allRows']->first();

        $this->assertEquals(10, $group['opening_row']['running_stock']);
        $this->assertEquals(1000, $group['opening_row']['running_avg']);
        $this->assertEquals(10000, $group['opening_row']['running_value']);
        $this->assertEquals('-', $group['opening_row']['mutation']);

        $this->assertEmpty($group['ledger_rows']);
        $this->assertEquals(10000, $result['totalValue']);
    }

    public function test_opening_balance_with_no_prior_activity_uses_fallback_avg()
    {
        $product = $this->createProduct([
            'average_purchase_price' => 1500
        ]);

        $filters = new InventoryValuationReportFilterData(
            Carbon::parse('2023-10-10 00:00:00'),
            Carbon::parse('2023-10-20 23:59:59')
        );

        $result = $this->service->getReport($filters, $this->setting->id);

        $group = $result['allRows']->first();

        $this->assertEquals(0, $group['opening_row']['running_stock']);
        $this->assertEquals(1500, $group['opening_row']['running_avg']);
        $this->assertEquals(0, $group['opening_row']['running_value']);
    }

    public function test_multi_day_range_with_purchase_and_sale()
    {
        $product = $this->createProduct(['average_purchase_price' => 1000]);

        // Purchase on 12th
        $this->createTransaction([
            'product_id' => $product->id,
            'type' => 'BUY',
            'quantity' => 10,
            'after_quantity' => 10,
            'reason' => 'Buy #BUY-01',
            'created_at' => Carbon::parse('2023-10-12 10:00:00')
        ]);
        
        // Sale on 15th
        $this->createTransaction([
            'product_id' => $product->id,
            'type' => 'SELL',
            'quantity' => -3,
            'previous_quantity' => 10,
            'after_quantity' => 7,
            'reason' => 'Sell #SELL-01',
            'created_at' => Carbon::parse('2023-10-15 10:00:00')
        ]);

        $filters = new InventoryValuationReportFilterData(
            Carbon::parse('2023-10-10 00:00:00'),
            Carbon::parse('2023-10-20 23:59:59')
        );

        $result = $this->service->getReport($filters, $this->setting->id);
        $group = $result['allRows']->first();

        $this->assertCount(2, $group['ledger_rows']);

        // Check purchase row
        $buyRow = $group['ledger_rows'][0];
        $this->assertEquals('2023-10-12', $buyRow['date']);
        $this->assertEquals('Pembelian', $buyRow['type_label']);
        $this->assertEquals(10, $buyRow['mutation']);
        $this->assertEquals(10, $buyRow['running_stock']);
        $this->assertEquals(1000, $buyRow['running_avg']);
        $this->assertEquals(10000, $buyRow['running_value']);

        // Check sale row
        $sellRow = $group['ledger_rows'][1];
        $this->assertEquals('2023-10-15', $sellRow['date']);
        $this->assertEquals('Penjualan', $sellRow['type_label']);
        $this->assertEquals(-3, $sellRow['mutation']);
        $this->assertEquals(7, $sellRow['running_stock']);
        $this->assertEquals(1000, $sellRow['running_avg']);
        $this->assertEquals(7000, $sellRow['running_value']);

        // Check subtotal
        $this->assertEquals(7, $group['subtotal']['stock']);
        $this->assertEquals(7000, $group['subtotal']['value']);
    }

    public function test_category_all_any_match_modes_and_product_id_narrowing()
    {
        $user = \App\Models\User::first();
        $cat2 = Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C2', 'category_name' => 'Cat2', 'created_by' => $user->id]);

        $prod1 = $this->createProduct(['product_code' => 'P1']);
        $prod2 = $this->createProduct(['category_id' => $cat2->id, 'product_code' => 'P2']);
        $prod3 = $this->createProduct(['product_code' => 'P3']);

        // any mode for cat1
        $filtersAny = new InventoryValuationReportFilterData(null, null, [$this->category->id], 'any');
        $resultAny = $this->service->getReport($filtersAny, $this->setting->id);
        $this->assertCount(2, $resultAny['allRows']);
        $this->assertContains($prod1->id, $resultAny['allRows']->pluck('product_id'));
        $this->assertContains($prod3->id, $resultAny['allRows']->pluck('product_id'));

        // product id narrowing
        $filtersProd = new InventoryValuationReportFilterData(null, null, [], 'any', [$prod2->id]);
        $resultProd = $this->service->getReport($filtersProd, $this->setting->id);
        $this->assertCount(1, $resultProd['allRows']);
        $this->assertEquals($prod2->id, $resultProd['allRows']->first()['product_id']);
    }

    public function test_per_product_subtotal_and_grand_total_across_all_pages()
    {
        for ($i = 0; $i < 5; $i++) {
            $product = $this->createProduct(['product_code' => 'P' . $i, 'average_purchase_price' => 1000]);
            $this->createTransaction([
                'product_id' => $product->id,
                'type' => 'BUY',
                'quantity' => 10,
                'after_quantity' => 10,
                'reason' => 'Buy',
                'created_at' => Carbon::now()
            ]);
        }

        $filters = new InventoryValuationReportFilterData();
        // paginate 2 per page
        $result = $this->service->getReport($filters, $this->setting->id, 2, 1);

        $this->assertCount(2, $result['paginator']->items()); // 2 on first page
        $this->assertCount(5, $result['allRows']); // 5 total
        
        $this->assertEquals(50000, $result['totalValue']); // 5 * 10 * 1000
    }

    public function test_setting_scoping()
    {
        $setting2 = Setting::factory()->create();
        \Modules\Setting\Entities\Location::create(["id" => 2, "setting_id" => $setting2->id, "name" => "Main 2"]);
        
        $productSetting1 = $this->createProduct(['product_code' => 'P1']);
        
        $user = \App\Models\User::first();
        $catSetting2 = Category::create(['setting_id' => $setting2->id, 'category_code' => 'C3', 'category_name' => 'Cat3', 'created_by' => $user->id]);
        $productSetting2 = Product::create([
            'setting_id' => $setting2->id,
            'category_id' => $catSetting2->id,
            'product_name' => 'Test Product 2',
            'product_code' => 'TEST2',
            'product_price' => 100,
            'product_cost' => 100,
            'stock_managed' => true,
            'average_purchase_price' => 0,
        ]);

        $this->createTransaction([
            'product_id' => $productSetting1->id,
            'type' => 'BUY',
            'quantity' => 10,
            'after_quantity' => 10,
            'reason' => 'Buy 1',
            'created_at' => Carbon::now()
        ]);

        Transaction::create([
            'setting_id' => $setting2->id,
            'product_id' => $productSetting2->id,
            'location_id' => 2,
            'type' => 'BUY',
            'current_quantity' => 0,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 0,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'quantity' => 20,
            'previous_quantity' => 0,
            'after_quantity' => 20,
            'reason' => 'Buy 2',
            'created_at' => Carbon::now()
        ]);

        $filters = new InventoryValuationReportFilterData();
        $result = $this->service->getReport($filters, $this->setting->id);

        $this->assertCount(1, $result['allRows']);
        $this->assertEquals($productSetting1->id, $result['allRows']->first()['product_id']);
    }
}
