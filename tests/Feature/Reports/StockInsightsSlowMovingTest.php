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
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class StockInsightsSlowMovingTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Location $location;
    protected StockInsightsQueryService $service;
    protected Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create();

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Gudang Pusat',
            'is_active' => true,
        ]);

        $this->service = app(StockInsightsQueryService::class);
        $this->now = Carbon::parse('2026-09-26 12:00:00');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_flags_mature_product_with_positive_good_stock_and_no_sales_as_slow_moving(): void
    {
        // Mature product: created 100 days ago
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Mature Slow Product',
            'product_code' => 'MSP-01',
            'product_cost' => 100,
            'product_price' => 200,
            'product_stock_alert' => 5,
            'is_active' => true,
            'stock_managed' => true,
            'merged_into_id' => null,
            'created_at' => $this->now->copy()->subDays(100),
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 15,
            'quantity_tax' => 10,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $filter = new StockInsightsFilterData(today: $this->now);

        $counts = $this->service->getAttentionCounts($filter);
        $this->assertEquals(1, $counts['slow_moving']);
    }

    public function test_suppresses_slow_moving_flag_if_product_age_is_less_than_90_days(): void
    {
        // Young product: created 30 days ago
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'New Product',
            'product_code' => 'NP-01',
            'product_cost' => 100,
            'product_price' => 200,
            'product_stock_alert' => 5,
            'is_active' => true,
            'stock_managed' => true,
            'merged_into_id' => null,
            'created_at' => $this->now->copy()->subDays(30),
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $filter = new StockInsightsFilterData(today: $this->now);

        $counts = $this->service->getAttentionCounts($filter);
        $this->assertEquals(0, $counts['slow_moving']);
    }

    public function test_suppresses_slow_moving_flag_if_global_good_stock_is_zero_or_negative(): void
    {
        // Mature product with 0 stock
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Mature Out of Stock Product',
            'product_code' => 'MOOSP-01',
            'product_cost' => 100,
            'product_price' => 200,
            'product_stock_alert' => 5,
            'is_active' => true,
            'stock_managed' => true,
            'merged_into_id' => null,
            'created_at' => $this->now->copy()->subDays(100),
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $filter = new StockInsightsFilterData(today: $this->now);

        $counts = $this->service->getAttentionCounts($filter);
        $this->assertEquals(0, $counts['slow_moving']);
    }

    public function test_suppresses_slow_moving_flag_if_eligible_sale_exists_within_90_days(): void
    {
        // Mature product with stock
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Mature Active Product',
            'product_code' => 'MAP-01',
            'product_cost' => 100,
            'product_price' => 200,
            'product_stock_alert' => 5,
            'is_active' => true,
            'stock_managed' => true,
            'merged_into_id' => null,
            'created_at' => $this->now->copy()->subDays(100),
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 20,
            'quantity_tax' => 20,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        // Sale 15 days ago, DISPATCHED, unarchived
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-' . Str::random(8),
            'date' => $this->now->copy()->subDays(15)->toDateString(),
            'reporting_date' => $this->now->copy()->subDays(15)->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 100000,
            'paid_amount' => 100000,
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
            'quantity' => 2,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $filter = new StockInsightsFilterData(today: $this->now);

        $counts = $this->service->getAttentionCounts($filter);
        $this->assertEquals(0, $counts['slow_moving']);
    }

    public function test_sale_outside_90_days_does_not_suppress_slow_moving_flag(): void
    {
        // Mature product with stock
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Mature Product Old Sale',
            'product_code' => 'MPOS-01',
            'product_cost' => 100,
            'product_price' => 200,
            'product_stock_alert' => 5,
            'is_active' => true,
            'stock_managed' => true,
            'merged_into_id' => null,
            'created_at' => $this->now->copy()->subDays(120),
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 20,
            'quantity_tax' => 20,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        // Sale 95 days ago
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'reference' => 'SL-' . Str::random(8),
            'date' => $this->now->copy()->subDays(95)->toDateString(),
            'reporting_date' => $this->now->copy()->subDays(95)->toDateString(),
            'status' => 'DISPATCHED',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 100000,
            'paid_amount' => 100000,
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
            'quantity' => 2,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $filter = new StockInsightsFilterData(today: $this->now);

        $counts = $this->service->getAttentionCounts($filter);
        $this->assertEquals(1, $counts['slow_moving']);
    }
}
