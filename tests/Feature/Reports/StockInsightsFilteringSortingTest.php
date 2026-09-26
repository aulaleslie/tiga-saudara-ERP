<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\StockInsightsFilterData;
use App\Services\Reports\StockInsightsQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class StockInsightsFilteringSortingTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Location $location;
    protected StockInsightsQueryService $service;
    protected Carbon $now;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \App\Models\User::factory()->create();

        $this->setting = Setting::factory()->create();

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Lokasi Utama',
            'is_active' => true,
        ]);

        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        $this->service = app(StockInsightsQueryService::class);
        $this->now = Carbon::parse('2026-09-26 12:00:00');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createProduct(array $attrs = []): Product
    {
        $product = Product::create(array_merge([
            'setting_id' => $this->setting->id,
            'product_name' => 'Produk ' . Str::random(5),
            'product_code' => 'PRD-' . Str::random(5),
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_stock_alert' => 5,
            'is_active' => true,
            'stock_managed' => true,
            'merged_into_id' => null,
            'created_at' => $this->now->copy()->subDays(100),
        ], $attrs));

        return $product;
    }

    protected function attachStock(Product $product, float $qty): void
    {
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => $qty,
            'quantity_tax' => $qty,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
    }

    public function test_tokenized_identity_search_matches_name_code_or_barcode(): void
    {
        $p1 = $this->createProduct(['product_name' => 'Kopi Robusta Premium', 'product_code' => 'KOB-001', 'barcode' => '899123456']);
        $p2 = $this->createProduct(['product_name' => 'Teh Melati Wangi', 'product_code' => 'TEH-002', 'barcode' => '899654321']);
        $this->attachStock($p1, 10);
        $this->attachStock($p2, 10);

        // Search by token in name
        $filter1 = new StockInsightsFilterData(search: 'Robusta', today: $this->now);
        $res1 = $this->service->paginate($filter1);
        $this->assertEquals(1, $res1->total());
        $this->assertEquals($p1->id, $res1->items()[0]->productId);

        // Search by code
        $filter2 = new StockInsightsFilterData(search: 'TEH-002', today: $this->now);
        $res2 = $this->service->paginate($filter2);
        $this->assertEquals(1, $res2->total());
        $this->assertEquals($p2->id, $res2->items()[0]->productId);

        // Search by barcode
        $filter3 = new StockInsightsFilterData(search: '899123456', today: $this->now);
        $res3 = $this->service->paginate($filter3);
        $this->assertEquals(1, $res3->total());
        $this->assertEquals($p1->id, $res3->items()[0]->productId);
    }

    public function test_category_and_brand_filters_scope_correctly(): void
    {
        $cat1 = Category::create(['setting_id' => $this->setting->id, 'category_name' => 'Minuman', 'category_code' => 'CAT1', 'created_by' => $this->user->id]);
        $cat2 = Category::create(['setting_id' => $this->setting->id, 'category_name' => 'Makanan', 'category_code' => 'CAT2', 'created_by' => $this->user->id]);
        $brand1 = Brand::create(['setting_id' => $this->setting->id, 'name' => 'Brand A', 'created_by' => $this->user->id]);
        $brand2 = Brand::create(['setting_id' => $this->setting->id, 'name' => 'Brand B', 'created_by' => $this->user->id]);

        $p1 = $this->createProduct(['product_name' => 'Minuman Brand A', 'category_id' => $cat1->id, 'brand_id' => $brand1->id]);
        $p2 = $this->createProduct(['product_name' => 'Makanan Brand A', 'category_id' => $cat2->id, 'brand_id' => $brand1->id]);
        $p3 = $this->createProduct(['product_name' => 'Makanan Brand B', 'category_id' => $cat2->id, 'brand_id' => $brand2->id]);
        $this->attachStock($p1, 10);
        $this->attachStock($p2, 10);
        $this->attachStock($p3, 10);

        // Filter category 1
        $filterCat = new StockInsightsFilterData(categoryIds: [$cat1->id], today: $this->now);
        $resCat = $this->service->paginate($filterCat);
        $this->assertEquals(1, $resCat->total());
        $this->assertEquals($p1->id, $resCat->items()[0]->productId);

        // Filter brand 1
        $filterBrand = new StockInsightsFilterData(brandIds: [$brand1->id], today: $this->now);
        $resBrand = $this->service->paginate($filterBrand);
        $this->assertEquals(2, $resBrand->total());

        // Filter both category 2 and brand 2
        $filterBoth = new StockInsightsFilterData(categoryIds: [$cat2->id], brandIds: [$brand2->id], today: $this->now);
        $resBoth = $this->service->paginate($filterBoth);
        $this->assertEquals(1, $resBoth->total());
        $this->assertEquals($p3->id, $resBoth->items()[0]->productId);
    }

    public function test_multi_status_filter_uses_or_semantics(): void
    {
        // P1: Stok Habis (stock = 0)
        $p1 = $this->createProduct(['product_name' => 'Produk Habis', 'product_stock_alert' => 5]);
        $this->attachStock($p1, 0);

        // P2: Perlu Dibeli Lagi (stock = 3 <= 5)
        $p2 = $this->createProduct(['product_name' => 'Produk Menipis', 'product_stock_alert' => 5]);
        $this->attachStock($p2, 3);

        // P3: Batas Minimum Belum Diatur (alert = 0, stock = 10)
        $p3 = $this->createProduct(['product_name' => 'Produk Tanpa Alert', 'product_stock_alert' => 0]);
        $this->attachStock($p3, 10);

        // P4: Cukup (alert = 5, stock = 20, sale yesterday so not slow moving)
        $p4 = $this->createProduct(['product_name' => 'Produk Cukup', 'product_stock_alert' => 5]);
        $this->attachStock($p4, 20);
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-' . Str::random(8),
            'date' => $this->now->copy()->subDays(1)->toDateString(),
            'reporting_date' => $this->now->copy()->subDays(1)->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $p4->id,
            'product_name' => $p4->product_name,
            'product_code' => $p4->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Filter single status: Stok Habis
        $filter1 = new StockInsightsFilterData(statuses: ['Stok Habis'], today: $this->now);
        $res1 = $this->service->paginate($filter1);
        $this->assertEquals(1, $res1->total());
        $this->assertEquals($p1->id, $res1->items()[0]->productId);

        // Filter multiple statuses: Stok Habis OR Perlu Dibeli Lagi
        $filterMulti = new StockInsightsFilterData(statuses: ['Stok Habis', 'Perlu Dibeli Lagi'], today: $this->now);
        $resMulti = $this->service->paginate($filterMulti);
        $this->assertEquals(2, $resMulti->total());
        $returnedIds = collect($resMulti->items())->pluck('productId')->all();
        $this->assertContains($p1->id, $returnedIds);
        $this->assertContains($p2->id, $returnedIds);
    }

    public function test_default_sort_prioritizes_statuses_then_product_name_and_id(): void
    {
        // P1: Status Cukup (priority 5)
        $pCukup = $this->createProduct(['product_name' => 'A Cukup', 'product_stock_alert' => 5]);
        $this->attachStock($pCukup, 20);
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-' . Str::random(8),
            'date' => $this->now->copy()->subDays(1)->toDateString(),
            'reporting_date' => $this->now->copy()->subDays(1)->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $pCukup->id,
            'product_name' => $pCukup->product_name,
            'product_code' => $pCukup->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // P2: Batas Minimum Belum Diatur (priority 3)
        $pTanpaAlert = $this->createProduct(['product_name' => 'B Tanpa Alert', 'product_stock_alert' => 0]);
        $this->attachStock($pTanpaAlert, 10);

        // P3: Perlu Dibeli Lagi (priority 2)
        $pMenipis = $this->createProduct(['product_name' => 'C Menipis', 'product_stock_alert' => 10]);
        $this->attachStock($pMenipis, 5);

        // P4: Stok Habis (priority 1)
        $pHabis = $this->createProduct(['product_name' => 'D Habis', 'product_stock_alert' => 5]);
        $this->attachStock($pHabis, 0);

        $filter = new StockInsightsFilterData(today: $this->now);
        $res = $this->service->paginate($filter);

        $items = $res->items();
        $this->assertCount(4, $items);
        $this->assertEquals($pHabis->id, $items[0]->productId);       // Priority 1: Stok Habis
        $this->assertEquals($pMenipis->id, $items[1]->productId);     // Priority 2: Perlu Dibeli Lagi
        $this->assertEquals($pTanpaAlert->id, $items[2]->productId);  // Priority 3: Batas Minimum Belum Diatur
        $this->assertEquals($pCukup->id, $items[3]->productId);       // Priority 5: Cukup
    }

    public function test_paginate_uses_bounded_queries_without_n_plus_one(): void
    {
        // Create 15 products with stock
        for ($i = 1; $i <= 15; $i++) {
            $p = $this->createProduct(['product_name' => "Batch Product {$i}"]);
            $this->attachStock($p, 10);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        $filter = new StockInsightsFilterData(perPage: 10, today: $this->now);
        $paginator = $this->service->paginate($filter);

        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertCount(10, $paginator->items());
        // Bounded queries: typically 1 count query, 1 select IDs query, 1 product details query, 1 grouped stock query, 1 grouped financials query, 1 grouped last sale date query <= 10 queries
        $this->assertLessThanOrEqual(10, count($queries), 'Query count must remain bounded and not scale per product/location.');
    }

    public function test_sorting_by_sold_quantity_and_financial_metrics_executes_valid_sql(): void
    {
        $p1 = $this->createProduct(['product_name' => 'Product Low Sold']);
        $p2 = $this->createProduct(['product_name' => 'Product High Sold']);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-' . Str::random(8),
            'date' => $this->now->toDateString(),
            'reporting_date' => $this->now->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $p1->id,
            'product_name' => $p1->product_name,
            'product_code' => $p1->product_code,
            'quantity' => 2,
            'price' => 5000,
            'unit_price' => 5000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 3000,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $p2->id,
            'product_name' => $p2->product_name,
            'product_code' => $p2->product_code,
            'quantity' => 8,
            'price' => 5000,
            'unit_price' => 5000,
            'sub_total' => 40000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 3000,
        ]);

        // Sort by sold_quantity desc
        $filterQty = new StockInsightsFilterData(sortColumn: 'sold_quantity', sortDirection: 'desc', today: $this->now);
        $resQty = $this->service->paginate($filterQty);
        $itemsQty = $resQty->items();
        $this->assertEquals($p2->id, $itemsQty[0]->productId);
        $this->assertEquals($p1->id, $itemsQty[1]->productId);

        // Sort by sales_value desc
        $filterVal = new StockInsightsFilterData(sortColumn: 'sales_value', sortDirection: 'desc', today: $this->now);
        $resVal = $this->service->paginate($filterVal);
        $itemsVal = $resVal->items();
        $this->assertEquals($p2->id, $itemsVal[0]->productId);
        $this->assertEquals($p1->id, $itemsVal[1]->productId);

        // Sort by sold_cost desc
        $filterCost = new StockInsightsFilterData(sortColumn: 'sold_cost', sortDirection: 'desc', today: $this->now);
        $resCost = $this->service->paginate($filterCost);
        $itemsCost = $resCost->items();
        $this->assertEquals($p2->id, $itemsCost[0]->productId);
        $this->assertEquals($p1->id, $itemsCost[1]->productId);

        // Sort by gross_profit desc
        $filterProfit = new StockInsightsFilterData(sortColumn: 'gross_profit', sortDirection: 'desc', today: $this->now);
        $resProfit = $this->service->paginate($filterProfit);
        $itemsProfit = $resProfit->items();
        $this->assertEquals($p2->id, $itemsProfit[0]->productId);
        $this->assertEquals($p1->id, $itemsProfit[1]->productId);
    }

    public function test_sales_value_sorting_accounts_for_header_discounts(): void
    {
        // Product A: 1 sale of 100,000 with 50,000 header discount => Net Sales Value = 50,000
        $pA = $this->createProduct(['product_name' => 'Product A']);
        // Product B: 1 sale of 60,000 with 0 header discount => Net Sales Value = 60,000
        $pB = $this->createProduct(['product_name' => 'Product B']);

        $saleA = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-A-' . Str::random(6),
            'date' => $this->now->toDateString(),
            'reporting_date' => $this->now->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 50000,
            'is_tax_included' => false,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);
        SaleDetails::create([
            'sale_id' => $saleA->id,
            'product_id' => $pA->id,
            'product_name' => $pA->product_name,
            'product_code' => $pA->product_code,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $saleB = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-B-' . Str::random(6),
            'date' => $this->now->toDateString(),
            'reporting_date' => $this->now->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 60000,
            'paid_amount' => 60000,
            'due_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);
        SaleDetails::create([
            'sale_id' => $saleB->id,
            'product_id' => $pB->id,
            'product_name' => $pB->product_name,
            'product_code' => $pB->product_code,
            'quantity' => 1,
            'price' => 60000,
            'unit_price' => 60000,
            'sub_total' => 60000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Sorting by sales_value DESC: Product B (60,000) should appear before Product A (50,000)
        $filter = new StockInsightsFilterData(sortColumn: 'sales_value', sortDirection: 'desc', today: $this->now);
        $res = $this->service->paginate($filter);
        $items = $res->items();

        $this->assertEquals($pB->id, $items[0]->productId);
        $this->assertEquals(60000, $items[0]->salesValue);
        $this->assertEquals($pA->id, $items[1]->productId);
        $this->assertEquals(50000, $items[1]->salesValue);
    }

    public function test_sales_value_sorting_matches_displayed_multi_line_discount_remainder_across_pages(): void
    {
        // 3 products in a single sale with fractional cents header discount:
        // DPP: P1 = 100.00, P2 = 100.00, P3 = 100.00 (Total DPP = 300.00)
        // Header discount: 10.00
        // P1: round(10.00 * 100 / 300, 2) = 3.33 => Net Sales Value = 96.67
        // P2: round(10.00 * 100 / 300, 2) = 3.33 => Net Sales Value = 96.67
        // P3 (last line): 10.00 - 3.33 - 3.33 = 3.34 => Net Sales Value = 96.66
        //
        // Also introduce benchmark Product Bench with exact sales value 96.665 (say 96.67 or 96.66)
        // With P_Bench having sales value 96.66:
        // Ordering DESC:
        // P1 (96.67), P2 (96.67), then P3 (96.66) or P_Bench (96.66)
        $p1 = $this->createProduct(['product_name' => 'Prod Line 1']);
        $p2 = $this->createProduct(['product_name' => 'Prod Line 2']);
        $p3 = $this->createProduct(['product_name' => 'Prod Line 3']);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-ML-' . Str::random(6),
            'date' => $this->now->toDateString(),
            'reporting_date' => $this->now->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 290.00,
            'paid_amount' => 290.00,
            'due_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 10.00,
            'is_tax_included' => false,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $p1->id,
            'product_name' => $p1->product_name,
            'product_code' => $p1->product_code,
            'quantity' => 1,
            'price' => 100.00,
            'unit_price' => 100.00,
            'sub_total' => 100.00,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $p2->id,
            'product_name' => $p2->product_name,
            'product_code' => $p2->product_code,
            'quantity' => 1,
            'price' => 100.00,
            'unit_price' => 100.00,
            'sub_total' => 100.00,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $p3->id,
            'product_name' => $p3->product_name,
            'product_code' => $p3->product_code,
            'quantity' => 1,
            'price' => 100.00,
            'unit_price' => 100.00,
            'sub_total' => 100.00,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Verify with page size 1: each page's item must match the exact SQL order and displayed sales value!
        // Page 1: P1 (96.67)
        $filterP1 = new StockInsightsFilterData(sortColumn: 'sales_value', sortDirection: 'desc', perPage: 1, page: 1, today: $this->now);
        $resP1 = $this->service->paginate($filterP1);
        $this->assertEquals($p1->id, $resP1->items()[0]->productId);
        $this->assertEquals(96.67, $resP1->items()[0]->salesValue);

        // Page 2: P2 (96.67)
        $filterP2 = new StockInsightsFilterData(sortColumn: 'sales_value', sortDirection: 'desc', perPage: 1, page: 2, today: $this->now);
        $resP2 = $this->service->paginate($filterP2);
        $this->assertEquals($p2->id, $resP2->items()[0]->productId);
        $this->assertEquals(96.67, $resP2->items()[0]->salesValue);

        // Page 3: P3 (96.66 - received 3.34 discount, so 96.66)
        $filterP3 = new StockInsightsFilterData(sortColumn: 'sales_value', sortDirection: 'desc', perPage: 1, page: 3, today: $this->now);
        $resP3 = $this->service->paginate($filterP3);
        $this->assertEquals($p3->id, $resP3->items()[0]->productId);
        $this->assertEquals(96.66, $resP3->items()[0]->salesValue);
    }

    public function test_sold_cost_sorting_uses_bundle_components_when_parent_snapshot_is_zero(): void
    {
        // Bundle parent product
        $bundleParent = $this->createProduct(['product_name' => 'Bundle Package']);
        $component = $this->createProduct(['product_name' => 'Single Item Component']);

        // Standard single product with known HPP = 15,000
        $singleProd = $this->createProduct(['product_name' => 'Regular Item']);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-BND-' . Str::random(6),
            'date' => $this->now->toDateString(),
            'reporting_date' => $this->now->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        // Detail 1: Bundle parent with cost_unit_snapshot = 0, but component has cost 20,000
        $detailBundle = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $bundleParent->id,
            'product_name' => $bundleParent->product_name,
            'product_code' => $bundleParent->product_code,
            'quantity' => 1,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 50000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 0, // Parent snapshot 0
            'cost_total_snapshot' => 0,
        ]);

        \Illuminate\Support\Facades\DB::table('sale_bundle_items')->insert([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detailBundle->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $component->id,
            'name' => 'Component Item',
            'quantity' => 2,
            'price' => 10000,
            'sub_total' => 20000,
            'cost_unit_snapshot' => 10000, // 2 * 10,000 = 20,000
            'cost_total_snapshot' => 20000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Detail 2: Single product with cost 15,000
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $singleProd->id,
            'product_name' => $singleProd->product_name,
            'product_code' => $singleProd->product_code,
            'quantity' => 1,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 50000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 15000,
            'cost_total_snapshot' => 15000,
        ]);

        // Sorting by sold_cost DESC: Bundle Parent (20,000) > Regular Item (15,000)
        $filter = new StockInsightsFilterData(sortColumn: 'sold_cost', sortDirection: 'desc', today: $this->now);
        $res = $this->service->paginate($filter);
        $items = $res->items();

        $this->assertEquals($bundleParent->id, $items[0]->productId);
        $this->assertEquals(20000, $items[0]->soldCost);
        $this->assertEquals($singleProd->id, $items[1]->productId);
        $this->assertEquals(15000, $items[1]->soldCost);
    }

    public function test_business_hierarchy_bounded_queries(): void
    {
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        $hierarchy = $this->service->getBusinessHierarchy();

        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertNotEmpty($hierarchy);
        // Only 2 queries: 1 for active locations and 1 for settings
        $this->assertLessThanOrEqual(2, count($queries), 'Business hierarchy must not perform N+1 queries per business setting.');
    }
}
