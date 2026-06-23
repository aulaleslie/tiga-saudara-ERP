<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Modules\Product\Entities\ProductStock;
use Modules\Currency\Entities\Currency;
use Carbon\Carbon;
use Livewire\Livewire;
use App\Services\Reports\WarehouseStockQuantityReportFilterData;
use App\Services\Reports\WarehouseStockQuantityReportQueryService;
use App\Livewire\Reports\WarehouseStockQuantityReport;

class WarehouseStockQuantityReportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $currency;
    protected $location1;
    protected $location2;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'stockMutationReports.access']);
        Role::firstOrCreate(['name' => 'Staff']);

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole('Staff');
        $this->user->givePermissionTo('stockMutationReports.access');

        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);

        $this->location1 = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse A'
        ]);

        $this->location2 = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse B'
        ]);
    }

    protected function makeCategory(string $code = 'C1', string $name = 'Category 1'): Category
    {
        return Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => $code,
            'category_name' => $name,
            'created_by' => $this->user->id
        ]);
    }

    protected function makeProduct(Category $category, string $code = 'P1', string $name = 'Product 1'): Product
    {
        return Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_code' => $code,
            'product_name' => $name,
            'product_unit' => 'PCS',
            'product_cost' => 1000,
            'product_price' => 1500,
            'stock_managed' => true,
            'product_quantity' => 0
        ]);
    }

    protected function makeTransaction(Product $product, Location $location, string $type, float $qty, string $date)
    {
        $trx = Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'location_id' => $location->id,
            'user_id' => $this->user->id,
            'type' => $type,
            'quantity' => $qty,
            'quantity_non_tax' => $qty,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'previous_quantity' => 0,
            'after_quantity' => $qty,
            'current_quantity' => $qty,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => $qty,
        ]);
        $trx->created_at = Carbon::parse($date);
        $trx->updated_at = Carbon::parse($date);
        $trx->save(['timestamps' => false]);
        return $trx;
    }

    /** @test */
    public function it_can_render_the_warehouse_stock_quantity_report_page_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.warehouse-stock-quantity.index'));
        $response->assertStatus(200);
        $response->assertSee('Kuantitas Stok Gudang');
    }

    /** @test */
    public function it_hides_the_report_from_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.warehouse-stock-quantity.index'));
        $response->assertStatus(403);
    }

    /** @test */
    public function it_calculates_as_of_product_location_quantity_including_historical_cutoff_and_future_exclusion()
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);

        // Transaction 1: Jan 1 (Past) -> +10
        $this->makeTransaction($product, $this->location1, 'init_stock', 10, '2023-01-01 10:00:00');
        
        // Transaction 2: Jan 15 (Past) -> -2
        $this->makeTransaction($product, $this->location1, 'sale', -2, '2023-01-15 10:00:00');

        // Transaction 3: Feb 5 (Future relative to cutoff) -> +5
        $this->makeTransaction($product, $this->location1, 'purchase', 5, '2023-02-05 10:00:00');

        $filter = new WarehouseStockQuantityReportFilterData(
            asOfDate: '2023-01-31',
            scopeSettingId: $this->setting->id,
            warehouseIds: [$this->location1->id]
        );

        $service = new WarehouseStockQuantityReportQueryService();
        $results = $service->build($filter);

        $this->assertCount(1, $results);
        $row = $results->first();
        
        // Should only sum transactions on or before Jan 31 -> 10 - 2 = 8
        $this->assertEquals(8, $row->{"qty_loc_{$this->location1->id}"});
        $this->assertEquals(8, $row->total_quantity);
    }

    /** @test */
    public function it_calculates_for_multiple_selected_warehouses_and_total_stok_sums_only_selected()
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);

        $this->makeTransaction($product, $this->location1, 'init_stock', 15, '2023-01-01 10:00:00');
        $this->makeTransaction($product, $this->location2, 'init_stock', 10, '2023-01-01 10:00:00');

        $location3 = Location::create(['setting_id' => $this->setting->id, 'name' => 'Warehouse C']);
        $this->makeTransaction($product, $location3, 'init_stock', 20, '2023-01-01 10:00:00');

        // Filter only for location 1 and 2
        $filter = new WarehouseStockQuantityReportFilterData(
            asOfDate: '2023-01-31',
            scopeSettingId: $this->setting->id,
            warehouseIds: [$this->location1->id, $this->location2->id]
        );

        $service = new WarehouseStockQuantityReportQueryService();
        $results = $service->build($filter);

        $this->assertCount(1, $results);
        $row = $results->first();

        $this->assertEquals(15, $row->{"qty_loc_{$this->location1->id}"});
        $this->assertEquals(10, $row->{"qty_loc_{$this->location2->id}"});
        
        // Total should only sum location 1 and 2, ignoring location 3's 20.
        $this->assertEquals(25, $row->total_quantity);
    }

    /** @test */
    public function it_retains_negative_and_zero_quantities()
    {
        $category = $this->makeCategory();
        $product1 = $this->makeProduct($category, 'P1', 'Product 1');
        $product2 = $this->makeProduct($category, 'P2', 'Product 2');

        // Product 1 ends at 0
        $this->makeTransaction($product1, $this->location1, 'init_stock', 10, '2023-01-01 10:00:00');
        $this->makeTransaction($product1, $this->location1, 'sale', -10, '2023-01-10 10:00:00');

        // Product 2 ends at -5
        $this->makeTransaction($product2, $this->location1, 'sale', -5, '2023-01-10 10:00:00');

        $filter = new WarehouseStockQuantityReportFilterData(
            asOfDate: '2023-01-31',
            scopeSettingId: $this->setting->id,
            warehouseIds: [$this->location1->id]
        );

        $service = new WarehouseStockQuantityReportQueryService();
        $results = $service->build($filter);

        $this->assertCount(2, $results);
        
        $p1 = $results->firstWhere('product_code', 'P1');
        $this->assertEquals(0, $p1->{"qty_loc_{$this->location1->id}"});
        $this->assertEquals(0, $p1->total_quantity);

        $p2 = $results->firstWhere('product_code', 'P2');
        $this->assertEquals(-5, $p2->{"qty_loc_{$this->location1->id}"});
        $this->assertEquals(-5, $p2->total_quantity);
    }

    /** @test */
    public function livewire_component_handles_filters_pagination_and_nullable_product_code()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $category = $this->makeCategory();
        $product = $this->makeProduct($category, '', 'Product Without Code');
        $this->makeTransaction($product, $this->location1, 'init_stock', 50, now()->format('Y-m-d H:i:s'));

        Livewire::test(WarehouseStockQuantityReport::class)
            ->assertSet('asOfDate', now()->format('Y-m-d'))
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSee('PRODUCT WITHOUT CODE')
            ->assertSee('-') // Nullable product code UI display
            ->assertSee('WAREHOUSE A')
            ->assertSee('50,00')
            ->assertSet('filterTriggered', true);
    }

    /** @test */
    public function livewire_shows_empty_state_when_no_active_warehouses()
    {
        $this->actingAs($this->user);
        
        // Create new setting with no locations
        $newSetting = Setting::create([
            'company_name' => 'Empty',
            'company_email' => 'empty@example.com',
            'company_phone' => '0000',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'empty@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);
        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($newSetting->id, ['role_id' => $staffRole->id]);
        
        session(['setting_id' => $newSetting->id]);

        Livewire::test(WarehouseStockQuantityReport::class)
            ->assertSee('Belum ada Gudang yang tersedia di sistem.');
    }
    /** @test */
    public function it_can_export_report_to_excel()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Maatwebsite\Excel\Facades\Excel::fake();

        Livewire::test(WarehouseStockQuantityReport::class)
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->call('exportExcel');

        $dateFormatted = now()->format('d-m-Y');
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded("kuantitas-stok-gudang_{$dateFormatted}.xlsx", function (\App\Exports\WarehouseStockQuantityReportExport $export) {
            $headings = $export->headings();
            $this->assertEquals([
                'Product Code',
                'Product Name',
                'WAREHOUSE A',
                'WAREHOUSE B',
                'Total Quantity',
                'Product Unit'
            ], $headings);

            $data = $export->collection()->toArray();
            if (count($data) > 0) {
                // Ensure null product codes are mapped to empty string
                $mapped = $export->map($data[0]);
                $this->assertEquals(['P1', 'PRODUCT A', 0.0, 0.0, 0.0, 'PCS'], $mapped);
            }
            return true;
        });
    }

    /** @test */
    public function it_can_export_report_to_csv()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Maatwebsite\Excel\Facades\Excel::fake();

        Livewire::test(WarehouseStockQuantityReport::class)
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->call('exportCsv');

        $dateFormatted = now()->format('d-m-Y');
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded("kuantitas-stok-gudang_{$dateFormatted}.csv", function (\App\Exports\WarehouseStockQuantityReportExport $export) {
            return true;
        });
    }

    /** @test */
    public function it_updates_asofdate_based_on_period_presets()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $component = Livewire::test(WarehouseStockQuantityReport::class);
        
        $component->set('periodPreset', 'Kuartal ini');
        $this->assertEquals(now()->endOfQuarter()->format('Y-m-d'), $component->get('asOfDate'));

        $component->set('periodPreset', 'Kuartal lalu');
        $this->assertEquals(now()->subQuarter()->endOfQuarter()->format('Y-m-d'), $component->get('asOfDate'));
    }

    /** @test */
    public function it_sorts_results_by_available_columns()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $category = $this->makeCategory();
        $this->makeProduct($category, 'B1', 'Z Product');
        $this->makeProduct($category, 'A1', 'A Product');

        $component = Livewire::test(WarehouseStockQuantityReport::class)
            ->set('asOfDate', now()->format('Y-m-d'))
            ->call('applyFilters');

        $results = $component->viewData('paginator')->items();
        $this->assertEquals('A PRODUCT', $results[0]->product_name);
        $this->assertEquals('Z PRODUCT', $results[1]->product_name);

        $component->call('sortBy', 'product_code');
        $results = $component->viewData('paginator')->items();
        $this->assertEquals('A1', $results[0]->product_code);
        $this->assertEquals('B1', $results[1]->product_code);

        $component->call('sortBy', 'product_code');
        $results = $component->viewData('paginator')->items();
        $this->assertEquals('B1', $results[0]->product_code);
        $this->assertEquals('A1', $results[1]->product_code);
    }
}
