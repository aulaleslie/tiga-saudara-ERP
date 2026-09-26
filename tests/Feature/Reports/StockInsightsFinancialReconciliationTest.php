<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\StockInsightsFilterData;
use App\Services\Reports\StockInsightsQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Sale\Services\SalesCostSnapshotService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class StockInsightsFinancialReconciliationTest extends TestCase
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
        return Product::create(array_merge([
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
    }

    public function test_persisted_sale_detail_used_without_double_deducting_sales_return(): void
    {
        $product = $this->createProduct(['product_name' => 'Produk Return Retain']);

        // Sale with quantity 8 already reduced from 10
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-' . Str::random(8),
            'date' => $this->now->copy()->subDays(3)->toDateString(),
            'reporting_date' => $this->now->copy()->subDays(3)->toDateString(),
            'status' => Sale::STATUS_RETURNED_PARTIALLY,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 80000,
            'paid_amount' => 80000,
            'due_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 8,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 80000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 6000,
            'cost_total_snapshot' => 48000,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
        ]);

        // Existing return record for 2 units (must NOT be deducted again)
        $saleReturn = SaleReturn::create([
            'setting_id' => $this->setting->id,
            'sale_id' => $sale->id,
            'date' => $this->now->copy()->subDays(2)->toDateString(),
            'reference' => 'RT-' . Str::random(8),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 20000,
            'paid_amount' => 20000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'sale_detail_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 20000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 6000,
            'cost_total_snapshot' => 12000,
            'cost_effective_at' => now(),
        ]);

        $filter = new StockInsightsFilterData(today: $this->now);
        $aggregates = $this->service->getSalesAndFinancialAggregates([$product->id], $filter->startDate, $filter->endDate);

        $this->assertEquals(8.0, $aggregates[$product->id]['sold_quantity']);
        $this->assertEquals(80000.0, $aggregates[$product->id]['sales_value']);
        $this->assertEquals(48000.0, $aggregates[$product->id]['sold_cost']);
        $this->assertEquals(32000.0, $aggregates[$product->id]['gross_profit']);
        $this->assertFalse($aggregates[$product->id]['is_cost_incomplete']);
        $this->assertEquals($this->now->copy()->subDays(3)->toDateString(), $aggregates[$product->id]['last_sale_date']);
    }

    public function test_proportional_deterministic_header_discount_allocation(): void
    {
        $p1 = $this->createProduct(['product_name' => 'Produk Disc 1']);
        $p2 = $this->createProduct(['product_name' => 'Produk Disc 2']);
        $p3 = $this->createProduct(['product_name' => 'Produk Disc 3']);

        // Sale with 3 lines: DPP 100,000, 200,000, 300,000 (Total DPP 600,000)
        // Header discount: 50,000
        // Expected shares:
        // P1: 50,000 * (100k / 600k) = 8,333.33
        // P2: 50,000 * (200k / 600k) = 16,666.67
        // P3: Remainder: 50,000 - 8,333.33 - 16,666.67 = 25,000.00
        // Total allocated: 50,000.00 exactly!
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-' . Str::random(8),
            'date' => $this->now->copy()->subDays(2)->toDateString(),
            'reporting_date' => $this->now->copy()->subDays(2)->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 550000,
            'paid_amount' => 550000,
            'due_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 50000,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $p1->id,
            'product_name' => $p1->product_name,
            'product_code' => $p1->product_code,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 60000,
            'cost_total_snapshot' => 60000,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $p2->id,
            'product_name' => $p2->product_name,
            'product_code' => $p2->product_code,
            'quantity' => 1,
            'price' => 200000,
            'unit_price' => 200000,
            'sub_total' => 200000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 120000,
            'cost_total_snapshot' => 120000,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $p3->id,
            'product_name' => $p3->product_name,
            'product_code' => $p3->product_code,
            'quantity' => 1,
            'price' => 300000,
            'unit_price' => 300000,
            'sub_total' => 300000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 180000,
            'cost_total_snapshot' => 180000,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
        ]);

        $filter = new StockInsightsFilterData(today: $this->now);
        $aggregates = $this->service->getSalesAndFinancialAggregates([$p1->id, $p2->id, $p3->id], $filter->startDate, $filter->endDate);

        $netRev1 = $aggregates[$p1->id]['sales_value'];
        $netRev2 = $aggregates[$p2->id]['sales_value'];
        $netRev3 = $aggregates[$p3->id]['sales_value'];

        $this->assertEquals(91666.67, $netRev1);
        $this->assertEquals(183333.33, $netRev2);
        $this->assertEquals(275000.0, $netRev3);

        // Sum of net revenue = 600,000 - 50,000 = 550,000
        $this->assertEquals(550000.0, round($netRev1 + $netRev2 + $netRev3, 2));
    }

    public function test_bundle_component_cost_attribution_and_incomplete_cost_flag(): void
    {
        $bundleParent = $this->createProduct(['product_name' => 'Paket Bundling Super']);
        $comp1 = $this->createProduct(['product_name' => 'Komponen A']);
        $comp2 = $this->createProduct(['product_name' => 'Komponen B (Missing Cost)']);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-' . Str::random(8),
            'date' => $this->now->copy()->subDays(1)->toDateString(),
            'reporting_date' => $this->now->copy()->subDays(1)->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 150000,
            'paid_amount' => 150000,
            'due_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $bundleParent->id,
            'product_name' => $bundleParent->product_name,
            'product_code' => $bundleParent->product_code,
            'quantity' => 1,
            'price' => 150000,
            'unit_price' => 150000,
            'sub_total' => 150000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 0,
            'cost_total_snapshot' => 0,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_NON_STOCK_MANAGED,
        ]);

        // Comp 1: Known snapshot 40,000 * 2 = 80,000
        SaleBundleItem::create([
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'product_id' => $comp1->id,
            'name' => $comp1->product_name,
            'price' => 0,
            'sub_total' => 0,
            'quantity' => 2,
            'cost_unit_snapshot' => 40000,
            'cost_total_snapshot' => 80000,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
        ]);

        // Comp 2: Missing snapshot
        SaleBundleItem::create([
            'bundle_id' => 1,
            'bundle_item_id' => 2,
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'product_id' => $comp2->id,
            'name' => $comp2->product_name,
            'price' => 0,
            'sub_total' => 0,
            'quantity' => 1,
            'cost_unit_snapshot' => 0,
            'cost_total_snapshot' => 0,
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_MISSING_AVERAGE_PRICE,
        ]);

        $filter = new StockInsightsFilterData(today: $this->now);
        $aggregates = $this->service->getSalesAndFinancialAggregates([$bundleParent->id], $filter->startDate, $filter->endDate);

        $this->assertEquals(150000.0, $aggregates[$bundleParent->id]['sales_value']);
        $this->assertEquals(80000.0, $aggregates[$bundleParent->id]['sold_cost']);
        $this->assertEquals(70000.0, $aggregates[$bundleParent->id]['gross_profit']);
        $this->assertTrue($aggregates[$bundleParent->id]['is_cost_incomplete']);
    }

    public function test_dpp_calculation_respects_is_tax_included_flag(): void
    {
        $pTaxInc = $this->createProduct(['product_name' => 'Tax Inc Product']);
        $pTaxExc = $this->createProduct(['product_name' => 'Tax Exc Product']);

        // Sale 1: Tax Included
        $sale1 = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-INC-' . Str::random(6),
            'date' => $this->now->toDateString(),
            'reporting_date' => $this->now->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 11100,
            'paid_amount' => 11100,
            'due_amount' => 0,
            'tax_amount' => 1100,
            'discount_amount' => 0,
            'is_tax_included' => true,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $pTaxInc->id,
            'product_name' => $pTaxInc->product_name,
            'product_code' => $pTaxInc->product_code,
            'quantity' => 1,
            'price' => 11100,
            'unit_price' => 11100,
            'sub_total' => 11100,
            'product_discount_amount' => 0,
            'product_tax_amount' => 1100,
            'cost_unit_snapshot' => 5000,
        ]);

        // Sale 2: Tax Excluded (price was 10,000, tax 1,100, sub_total 10,000, total_amount 11,100)
        $sale2 = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-EXC-' . Str::random(6),
            'date' => $this->now->toDateString(),
            'reporting_date' => $this->now->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 11100,
            'paid_amount' => 11100,
            'due_amount' => 0,
            'tax_amount' => 1100,
            'discount_amount' => 0,
            'is_tax_included' => false,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $pTaxExc->id,
            'product_name' => $pTaxExc->product_name,
            'product_code' => $pTaxExc->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 1100,
            'cost_unit_snapshot' => 5000,
        ]);

        $filter = new StockInsightsFilterData(today: $this->now);
        $aggregates = $this->service->getSalesAndFinancialAggregates(
            [$pTaxInc->id, $pTaxExc->id],
            $filter->startDate,
            $filter->endDate
        );

        // For tax included: DPP = sub_total (11,100) - tax (1,100) = 10,000
        $this->assertEquals(10000.0, $aggregates[$pTaxInc->id]['sales_value']);
        // For tax excluded: DPP = sub_total (10,000) directly (not subtracted again)
        $this->assertEquals(10000.0, $aggregates[$pTaxExc->id]['sales_value']);
    }

    public function test_sold_cost_uses_unit_cost_snapshot_times_quantity(): void
    {
        $product = $this->createProduct(['product_name' => 'Return Reduced Product']);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-RET-' . Str::random(6),
            'date' => $this->now->toDateString(),
            'reporting_date' => $this->now->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 15000,
            'paid_amount' => 15000,
            'due_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        // Detail originally had quantity 3 and cost_total_snapshot 15,000 (unit 5,000).
        // Due to POS return, quantity was reduced to 1, but cost_total_snapshot remained 15,000.
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 5000,
            'cost_total_snapshot' => 15000, // Stale total snapshot
            'cost_snapshot_source' => SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE,
        ]);

        $filter = new StockInsightsFilterData(today: $this->now);
        $aggregates = $this->service->getSalesAndFinancialAggregates(
            [$product->id],
            $filter->startDate,
            $filter->endDate
        );

        // Sold cost must use cost_unit_snapshot * current quantity = 5,000 * 1 = 5,000 (not stale 15,000)
        $this->assertEquals(5000.0, $aggregates[$product->id]['sold_cost']);
        $this->assertEquals(5000.0, $aggregates[$product->id]['gross_profit']);
    }
}
