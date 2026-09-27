<?php

namespace Tests\Unit\Services\Reports;

use App\Services\Reports\InventorySummaryReportFilterData;
use App\Services\Reports\InventorySummaryReportQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class InventorySummaryReportQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private InventorySummaryReportQueryService $service;

    private \Modules\Product\Entities\Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $user = \App\Models\User::factory()->create();
        $this->setting = Setting::factory()->create();
        $this->category = \Modules\Product\Entities\Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C1', 'category_name' => 'Cat1', 'created_by' => $user->id]);
        \Modules\Setting\Entities\Location::create(["id" => 1, "setting_id" => $this->setting->id, "name" => "Main"]);
        $this->service = new InventorySummaryReportQueryService();
    }

    public function test_it_calculates_running_stock_and_average_correctly()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST',
            'product_price' => 100,
            'product_cost' => 100,
            'stock_managed' => true,
            'average_purchase_price' => 150,
        ]);

        $date = now()->subDays(5);

        $tx = Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'location_id' => 1, 'type' => 'BUY',
            'current_quantity' => 0, 'previous_quantity' => 0, 'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'quantity' => 10,
            'previous_quantity' => 0,
            'after_quantity' => 10,
        ]);
        $tx->created_at = $date->clone();
        $tx->save();

        $filters = new InventorySummaryReportFilterData(
            asOfDate: $date->clone()->addDay(),
            stockStatus: 'available'
        );

        $result = $this->service->getSummary($filters, $this->setting->id);

        $this->assertCount(1, $result['allRows']);
        $row = $result['allRows']->first();
        $this->assertEquals(10, $row['stock']);
        $this->assertEquals(150, $row['average_cost']);
        $this->assertEquals(1500, $row['value']);
    }

    public function test_it_handles_negative_stock()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST',
            'product_price' => 100,
            'product_cost' => 100,
            'stock_managed' => true,
            'average_purchase_price' => 100,
        ]);

        Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'location_id' => 1, 'type' => 'SELL',
            'current_quantity' => 0, 'previous_quantity' => 0, 'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'quantity' => -5,
            'previous_quantity' => 0,
            'after_quantity' => -5,
            'created_at' => now()->subDay(),
            'date' => now()->subDay()->format('Y-m-d'),
        ]);

        $filters = new InventorySummaryReportFilterData(
            asOfDate: now(),
            stockStatus: ''
        );

        $result = $this->service->getSummary($filters, $this->setting->id);

        $this->assertCount(1, $result['allRows']);
        $row = $result['allRows']->first();
        $this->assertEquals(-5, $row['stock']);
        $this->assertEquals(100, $row['average_cost']);
        $this->assertEquals(-500, $row['value']);
    }

    public function test_it_filters_by_stock_status()
    {
        $product1 = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Test Product 1',
            'product_code' => 'TEST1',
            'product_price' => 100,
            'product_cost' => 100,
            'stock_managed' => true,
        ]);

        $product2 = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => 1,
            'product_name' => 'Test Product 2',
            'product_code' => 'TEST2',
            'product_price' => 100,
            'product_cost' => 100,
            'stock_managed' => true,
        ]);

        Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product2->id,
            'location_id' => 1, 'type' => 'BUY',
            'current_quantity' => 0, 'previous_quantity' => 0, 'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'quantity' => 10,
            'previous_quantity' => 0,
            'after_quantity' => 10,
            'created_at' => now()->subDay(),
            'date' => now()->subDay()->format('Y-m-d'),
        ]);

        $filtersOut = new InventorySummaryReportFilterData(asOfDate: now(), stockStatus: 'out_of_stock');
        $resultOut = $this->service->getSummary($filtersOut, $this->setting->id);
        $this->assertCount(1, $resultOut['allRows']);
        $this->assertEquals($product1->id, $resultOut['allRows']->first()['product_id']);

        $filtersAvail = new InventorySummaryReportFilterData(asOfDate: now(), stockStatus: 'available');
        $resultAvail = $this->service->getSummary($filtersAvail, $this->setting->id);
        $this->assertCount(1, $resultAvail['allRows']);
        $this->assertEquals($product2->id, $resultAvail['allRows']->first()['product_id']);
    }

    public function test_it_reconstructs_stock_across_purchases_sales_and_adjustments()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST',
            'product_price' => 100,
            'product_cost' => 100,
            'stock_managed' => true,
        ]);

        // BUY +10
        Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'location_id' => 1, 'type' => 'BUY',
            'current_quantity' => 0, 'previous_quantity' => 0, 'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'quantity' => 10,
            'after_quantity' => 10,
            'created_at' => now()->subDays(5),
            'date' => now()->subDays(5)->format('Y-m-d'),
        ]);

        // SELL -2
        Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'location_id' => 1, 'type' => 'SELL',
            'current_quantity' => 0, 'previous_quantity' => 0, 'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'quantity' => -2,
            'after_quantity' => 8,
            'created_at' => now()->subDays(4),
            'date' => now()->subDays(4)->format('Y-m-d'),
        ]);

        // ADJ +5
        Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'location_id' => 1, 'type' => 'ADJ',
            'current_quantity' => 0, 'previous_quantity' => 0, 'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'quantity' => 0,
            'previous_quantity' => 8,
            'after_quantity' => 13,
            'created_at' => now()->subDays(3),
            'date' => now()->subDays(3)->format('Y-m-d'),
        ]);

        $filters = new InventorySummaryReportFilterData(
            asOfDate: now(),
            stockStatus: 'available'
        );

        $result = $this->service->getSummary($filters, $this->setting->id);

        $this->assertCount(1, $result['allRows']);
        $this->assertEquals(13, $result['allRows']->first()['stock']);
    }

    public function test_it_handles_initialization_only_products()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Init Product',
            'product_code' => 'INIT',
            'product_price' => 100,
            'product_cost' => 100,
            'stock_managed' => true,
        ]);

        // No transactions

        $filters = new InventorySummaryReportFilterData(
            asOfDate: now(),
            stockStatus: ''
        );

        $result = $this->service->getSummary($filters, $this->setting->id);

        $this->assertCount(1, $result['allRows']);
        $this->assertEquals(0, $result['allRows']->first()['stock']);
    }

    public function test_it_handles_nullable_product_code_and_minimum_stock()
    {
        Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Null Code Product',
            'product_code' => null,
            'product_price' => 100,
            'product_cost' => 100,
            'product_stock_alert' => 5,
            'stock_managed' => true,
        ]);

        $filters = new InventorySummaryReportFilterData(
            asOfDate: now(),
            stockStatus: ''
        );

        $result = $this->service->getSummary($filters, $this->setting->id);

        $this->assertCount(1, $result['allRows']);
        $row = $result['allRows']->first();
        $this->assertNull($row['product_code']);
        $this->assertEquals(5, $row['minimum_stock']);
    }

    public function test_it_filters_by_product_and_category()
    {
        $category2 = \Modules\Product\Entities\Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C2', 'category_name' => 'Cat2', 'created_by' => \App\Models\User::factory()->create()->id]);

        $product1 = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Product 1',
            'product_code' => 'P1',
            'product_price' => 100,
            'product_cost' => 100,
            'stock_managed' => true,
        ]);

        $product2 = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category2->id,
            'product_name' => 'Product 2',
            'product_code' => 'P2',
            'product_price' => 100,
            'product_cost' => 100,
            'stock_managed' => true,
        ]);

        $filters = new InventorySummaryReportFilterData(
            asOfDate: now(),
            stockStatus: '',
            categoryIds: [$category2->id],
            categoryMatchMode: 'any'
        );

        $result = $this->service->getSummary($filters, $this->setting->id);

        $this->assertCount(1, $result['allRows']);
        $this->assertEquals($product2->id, $result['allRows']->first()['product_id']);
    }

    public function test_it_sorts_results()
    {
        Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'A Product',
            'product_code' => 'A',
            'product_price' => 100,
            'product_cost' => 100,
            'stock_managed' => true,
        ]);

        Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'B Product',
            'product_code' => 'B',
            'product_price' => 100,
            'product_cost' => 100,
            'stock_managed' => true,
        ]);

        $filters = new InventorySummaryReportFilterData(
            asOfDate: now(),
            stockStatus: '',
            sortColumn: 'product_name',
            sortDirection: 'desc'
        );

        $result = $this->service->getSummary($filters, $this->setting->id);

        $this->assertCount(2, $result['allRows']);
        $this->assertEquals('B PRODUCT', $result['allRows']->first()['product_name']);
        $this->assertEquals('A PRODUCT', $result['allRows']->last()['product_name']);
    }

    public function test_it_calculates_total_value()
    {
        $product1 = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Product 1',
            'product_code' => 'P1',
            'product_price' => 100,
            'product_cost' => 100,
            'average_purchase_price' => 10,
            'stock_managed' => true,
        ]);

        $product2 = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Product 2',
            'product_code' => 'P2',
            'product_price' => 100,
            'product_cost' => 100,
            'average_purchase_price' => 20,
            'stock_managed' => true,
        ]);

        Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product1->id,
            'location_id' => 1, 'type' => 'BUY',
            'current_quantity' => 0, 'previous_quantity' => 0, 'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'quantity' => 5,
            'after_quantity' => 5,
            'created_at' => now()->subDays(5),
            'date' => now()->subDays(5)->format('Y-m-d'),
        ]);

        Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product2->id,
            'location_id' => 1, 'type' => 'BUY',
            'current_quantity' => 0, 'previous_quantity' => 0, 'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'quantity' => 2,
            'after_quantity' => 2,
            'created_at' => now()->subDays(5),
            'date' => now()->subDays(5)->format('Y-m-d'),
        ]);

        $filters = new InventorySummaryReportFilterData(
            asOfDate: now(),
            stockStatus: ''
        );

        $result = $this->service->getSummary($filters, $this->setting->id);

        // 5 * 10 + 2 * 20 = 50 + 40 = 90
        $this->assertEquals(90, $result['totalValue']);
    }
}
