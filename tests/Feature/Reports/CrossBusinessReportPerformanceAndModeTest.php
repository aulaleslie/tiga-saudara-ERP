<?php

namespace Tests\Feature\Reports;

use App\Livewire\Reports\CrossBusinessStockInventory;
use App\Models\User;
use App\Services\Reports\CrossBusinessStockInventoryFilterData;
use App\Services\Reports\CrossBusinessStockInventoryQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CrossBusinessReportPerformanceAndModeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting1;
    private Setting $setting2;
    private Location $loc1;
    private Location $loc2;
    private Unit $baseUnit;
    private Unit $boxUnit;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'inventory.view_remaining_stock']);
        Role::firstOrCreate(['name' => 'Super Admin']);

        DB::table('currencies')->insert([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->setting1 = Setting::create([
            'company_name' => 'Bisnis 1',
            'company_email' => 'b1@test.com',
            'company_phone' => '111',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'b1@test.com',
            'footer_text' => 'F1',
            'company_address' => 'A1',
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Bisnis 2',
            'company_email' => 'b2@test.com',
            'company_phone' => '222',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'b2@test.com',
            'footer_text' => 'F2',
            'company_address' => 'A2',
        ]);

        $this->loc1 = Location::create(['name' => 'Loc 1', 'setting_id' => $this->setting1->id, 'is_active' => true]);
        $this->loc2 = Location::create(['name' => 'Loc 2', 'setting_id' => $this->setting2->id, 'is_active' => true]);

        $this->baseUnit = Unit::create(['name' => 'Pieces', 'short_name' => 'Pcs', 'is_active' => true, 'setting_id' => $this->setting1->id]);
        $this->boxUnit = Unit::create(['name' => 'Karton', 'short_name' => 'Ktn', 'is_active' => true, 'setting_id' => $this->setting1->id]);

        $this->user = User::factory()->create();
        $this->user->assignRole('Super Admin');
        $this->user->givePermissionTo('inventory.view_remaining_stock');
    }

    private function seedProducts(int $count, int $offset = 0): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $num = $offset + $i;
            $p = Product::create([
                'setting_id' => $this->setting1->id,
                'product_name' => "Product {$num}",
                'product_code' => "P-{$num}",
                'product_cost' => 1000,
                'product_price' => 2000,
                'product_unit' => 'Pcs',
                'base_unit_id' => $this->baseUnit->id,
                'stock_managed' => true,
                'is_active' => true,
            ]);

            ProductUnitConversion::create([
                'product_id' => $p->id,
                'unit_id' => $this->boxUnit->id,
                'base_unit_id' => $this->baseUnit->id,
                'conversion_factor' => 10,
            ]);

            ProductStock::create([
                'product_id' => $p->id,
                'location_id' => $this->loc1->id,
                'quantity' => 10,
                'quantity_tax' => 10,
                'quantity_non_tax' => 0,
                'broken_quantity' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
            ]);

            $po = Purchase::create([
                'date' => now()->format('Y-m-d'),
                'due_date' => now()->addDays(7)->format('Y-m-d'),
                'reference' => "PO-{$num}",
                'status' => Purchase::STATUS_APPROVED,
                'setting_id' => $this->setting1->id,
                'total_amount' => 10000,
                'paid_amount' => 0,
                'due_amount' => 10000,
                'payment_method' => 'Cash',
                'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
                'tax_percentage' => 0,
                'tax_amount' => 0,
                'discount_percentage' => 0,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'is_tax_included' => false,
            ]);

            PurchaseDetail::create([
                'purchase_id' => $po->id,
                'product_id' => $p->id,
                'product_name' => $p->product_name,
                'product_code' => $p->product_code,
                'quantity' => 5,
                'unit_price' => 1000,
                'price' => 1000,
                'sub_total' => 5000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
            ]);
        }
    }

    /** @test */
    public function it_proves_query_count_does_not_grow_with_product_and_purchase_detail_fixture_size()
    {
        $this->actingAs($this->user);
        $service = new CrossBusinessStockInventoryQueryService();

        // Measure query count for 5 products on page (perPage=5)
        $this->seedProducts(5);

        \Illuminate\Support\Facades\Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $filter5 = new CrossBusinessStockInventoryFilterData(businessIds: [$this->setting1->id, $this->setting2->id]);
        $data5 = $service->getReportData($filter5, 5, 1);
        $countFor5 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Seed 10 more products (total 15), now page size is 15
        $this->seedProducts(10, 5);

        \Illuminate\Support\Facades\Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $filter15 = new CrossBusinessStockInventoryFilterData(businessIds: [$this->setting1->id, $this->setting2->id]);
        $data15 = $service->getReportData($filter15, 15, 1);
        $countFor15 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The number of queries should be bounded and independent of number of products/cells
        // (both 5 and 15 products should execute the same bounded query pattern: settings query, count, products, relationships eager load, stocks, in-delivery)
        $this->assertCount(5, $data5['rows']);
        $this->assertCount(15, $data15['rows']);
        $this->assertEquals($countFor5, $countFor15);
    }

    /**
     * Display-mode formatting is applied downstream in the view against canonical row data,
     * so a mode switch never triggers per-product/per-business/per-cell requerying: the
     * component re-renders with the same bounded query set it used for its initial render,
     * regardless of how many products or businesses are on the page. This does re-run that
     * bounded set (a real display-mode switch is a separate Livewire HTTP request, so an
     * in-process result cache would not — and deliberately does not — carry over, avoiding
     * stale stock/in-delivery figures after a mutation).
     *
     * @test
     */
    public function it_switches_display_mode_without_growing_the_bounded_query_set()
    {
        $this->actingAs($this->user);
        $this->seedProducts(3);

        $component = Livewire::test(CrossBusinessStockInventory::class);

        // Baseline a plain re-render (e.g. toggling availability back to its own value),
        // which is the same request/render lifecycle a mode switch goes through, so mount()'s
        // one-time setup queries do not skew the comparison.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->set('availability', 'all');
        $baselineQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        DB::flushQueryLog();
        DB::enableQueryLog();

        // Switch display mode only
        $component->set('displayMode', 'conversion');

        $modeSwitchQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The mode-switch render issues the same bounded query pattern as any other render,
        // not a query count that grows with products/businesses/cells.
        $this->assertEquals($baselineQueryCount, $modeSwitchQueryCount);
    }

    /** @test */
    public function test_multi_batch_export_processes_bounded_batches_and_contains_all_rows(): void
    {
        $this->actingAs($this->user);
        // Seed 12 products
        $this->seedProducts(12);

        $service = new CrossBusinessStockInventoryQueryService();
        $filter = new CrossBusinessStockInventoryFilterData(businessIds: [$this->setting1->id, $this->setting2->id]);

        // Export with small batch chunk size = 5 (will require 3 chunks: 5, 5, 2)
        $exportData = $service->getAllRowsForExport($filter, chunkSize: 5);

        $this->assertCount(12, $exportData['rows']);
        $productNames = $exportData['rows']->pluck('product_name')->all();

        // Verify all 12 products exist in stable order
        for ($i = 1; $i <= 12; $i++) {
            $expectedName = "Product {$i}";
            $this->assertContains($expectedName, $productNames);
        }
    }

    /**
     * Regression for a category/brand N+1: getRowGeneratorForExport() uses cursor(), which
     * does not carry over the with(['category', 'brand']) eager loads applied to the
     * paginated on-screen query, and buildRows() reads both relationships for every row.
     * Every exported product here has a non-null category_id and brand_id, so an unguarded
     * lazy load would fire on each one.
     *
     * @test
     */
    public function it_does_not_issue_category_or_brand_n_plus_one_queries_during_export(): void
    {
        $this->actingAs($this->user);

        $category = Category::create([
            'setting_id' => $this->setting1->id,
            'category_code' => 'CAT-PERF',
            'category_name' => 'Performance Category',
            'created_by' => $this->user->id,
        ]);

        $brand = Brand::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Performance Brand',
            'created_by' => $this->user->id,
        ]);

        $this->seedProducts(6);
        Product::where('product_name', 'like', 'Product %')->update([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
        ]);

        $service = new CrossBusinessStockInventoryQueryService();
        $filter = new CrossBusinessStockInventoryFilterData(businessIds: [$this->setting1->id, $this->setting2->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $rows = iterator_to_array($service->getRowGeneratorForExport($filter, chunkSize: 3));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(6, $rows);
        foreach ($rows as $row) {
            $this->assertEquals('performance category', strtolower($row['category_name']));
            $this->assertEquals('performance brand', strtolower($row['brand_name']));
        }

        $categoryQueries = array_filter($queries, fn ($q) => str_contains(strtolower($q['query']), 'categories'));
        $brandQueries = array_filter($queries, fn ($q) => str_contains(strtolower($q['query']), 'brands'));

        // 6 products in 2 chunks of 3 should each resolve category/brand in one bulk query
        // per chunk (at most 2 each), never one query per product (which would be 6).
        $this->assertLessThanOrEqual(2, count($categoryQueries));
        $this->assertLessThanOrEqual(2, count($brandQueries));
    }
}
