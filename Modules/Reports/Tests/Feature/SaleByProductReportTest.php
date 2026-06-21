<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\People\Entities\Customer;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Livewire\Livewire;
use App\Services\Reports\SaleByProductReportFilterData;
use App\Services\Reports\SaleByProductReportQueryService;
use App\Livewire\Reports\SaleByProductReport;
use App\Exports\SaleByProductReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;

class SaleByProductReportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'saleReports.access']);
        Role::firstOrCreate(['name' => 'Staff']);

        $currency = Currency::create([
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
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole('Staff');
        $this->user->givePermissionTo('saleReports.access');

        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
    }

    protected function makeCustomer(string $name = 'Customer 1', ?int $settingId = null): Customer
    {
        static $counter = 0;
        $counter++;
        return Customer::create([
            'setting_id' => $settingId ?? $this->setting->id,
            'customer_name' => $name,
            'customer_email' => "c{$counter}@test.com",
            'customer_phone' => (string) $counter,
            'address' => 'A',
            'city' => 'C',
            'country' => 'C',
        ]);
    }

    protected function makeCategory(string $name = 'Category 1'): Category
    {
        static $counter = 0;
        $counter++;
        return Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => "CAT-{$counter}",
            'category_name' => $name,
        ]);
    }

    protected function makeProduct(array $overrides = []): Product
    {
        static $counter = 0;
        $counter++;
        return Product::create(array_merge([
            'setting_id' => $this->setting->id,
            'product_name' => "Product {$counter}",
            'product_code' => "PRD-{$counter}",
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'Pcs',
        ], $overrides));
    }

    protected function makeSale(Customer $customer, array $overrides = []): Sale
    {
        static $ref = 0;
        $ref++;
        return Sale::create(array_merge([
            'date' => now()->format('Y-m-d'),
            'setting_id' => $this->setting->id,
            'reference' => 'SL-' . str_pad($ref, 4, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => 'Completed',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'is_tax_included' => false,
        ], $overrides));
    }

    protected function makeSaleDetail(Sale $sale, array $overrides = []): SaleDetails
    {
        return SaleDetails::create(array_merge([
            'sale_id' => $sale->id,
            'product_id' => null,
            'quantity' => 1,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_name' => 'Product A',
            'product_code' => 'PA-001',
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ], $overrides));
    }

    protected function makeSaleReturn(Customer $customer, array $overrides = []): SaleReturn
    {
        static $ref = 0;
        $ref++;
        return SaleReturn::create(array_merge([
            'date' => now()->format('Y-m-d'),
            'setting_id' => $this->setting->id,
            'reference' => 'SR-' . str_pad($ref, 4, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => $overrides['status'] ?? 'Completed',
            'payment_status' => $overrides['payment_status'] ?? 'Unpaid',
            'payment_method' => $overrides['payment_method'] ?? 'Cash',
            'total_amount' => $overrides['total_amount'] ?? 1000,
            'paid_amount' => $overrides['paid_amount'] ?? 0,
            'due_amount' => 1000,
        ], $overrides));
    }

    protected function makeSaleReturnDetail(SaleReturn $saleReturn, array $overrides = []): SaleReturnDetail
    {
        return SaleReturnDetail::create(array_merge([
            'sale_return_id' => $saleReturn->id,
            'product_id' => null,
            'product_name' => 'Product A',
            'product_code' => 'PA-001',
            'price' => 1000,
            'quantity' => 1,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ], $overrides));
    }

    /** @test */
    public function it_can_render_the_sale_by_product_report_page_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.sale-by-product.index'));
        $response->assertStatus(200);
        $response->assertSee('Penjualan per Produk');
    }

    /** @test */
    public function it_hides_the_report_from_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.sale-by-product.index'));
        $response->assertStatus(403);
    }

    /** @test */
    public function it_asserts_no_migration_is_needed_for_sale_by_product_report()
    {
        $this->assertTrue(
            Schema::hasTable('sales') && Schema::hasTable('sale_details'),
            'Regression: Sales tables are missing.'
        );

        $this->assertTrue(
            Schema::hasTable('sale_returns') && Schema::hasTable('sale_return_details'),
            'Regression: Sales Return tables are missing.'
        );

        $this->assertTrue(
            Schema::hasColumn('sales', 'is_tax_included'),
            'Regression: sales.is_tax_included column is missing.'
        );
    }

    /** @test */
    public function it_filters_by_sale_and_return_dates_and_setting()
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        
        $saleOut = $this->makeSale($customer, ['date' => '2020-01-01']);
        $this->makeSaleDetail($saleOut, ['product_id' => $product->id, 'quantity' => 2, 'sub_total' => 2000]);

        $saleIn = $this->makeSale($customer, ['date' => now()->format('Y-m-d')]);
        $this->makeSaleDetail($saleIn, ['product_id' => $product->id, 'quantity' => 5, 'sub_total' => 5000]);

        $returnOut = $this->makeSaleReturn($customer, ['date' => '2020-01-01', 'sale_id' => $saleOut->id]);
        $this->makeSaleReturnDetail($returnOut, ['product_id' => $product->id, 'quantity' => 1, 'sub_total' => 1000]);

        $returnIn = $this->makeSaleReturn($customer, ['date' => now()->format('Y-m-d'), 'sale_id' => $saleIn->id]);
        $this->makeSaleReturnDetail($returnIn, ['product_id' => $product->id, 'quantity' => 2, 'sub_total' => 2000]);

        $filter = new SaleByProductReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleByProductReportQueryService();
        $results = $queryService->build($filter)->get();

        $this->assertCount(1, $results);
        $this->assertEquals(5, $results[0]->sold_quantity);
        $this->assertEquals(5000, $results[0]->sold_value);
        $this->assertEquals(2, $results[0]->return_quantity);
        $this->assertEquals(2000, $results[0]->return_value);
    }

    /** @test */
    public function it_filters_received_return_status()
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $sale = $this->makeSale($customer);
        $this->makeSaleDetail($sale, ['product_id' => $product->id]);

        $returnPending = $this->makeSaleReturn($customer, ['status' => 'Pending', 'sale_id' => $sale->id]);
        $this->makeSaleReturnDetail($returnPending, ['product_id' => $product->id, 'quantity' => 1]);

        $returnAwaiting = $this->makeSaleReturn($customer, ['status' => 'Awaiting Settlement', 'sale_id' => $sale->id]);
        $this->makeSaleReturnDetail($returnAwaiting, ['product_id' => $product->id, 'quantity' => 2, 'sub_total' => 2000]);

        $filter = new SaleByProductReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleByProductReportQueryService();
        $results = $queryService->build($filter)->get();

        $this->assertCount(1, $results);
        $this->assertEquals(2, $results[0]->return_quantity);
    }

    /** @test */
    public function it_calculates_tax_exclusive_value_and_average_price()
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $saleTaxIncluded = $this->makeSale($customer, ['is_tax_included' => true]);
        $this->makeSaleDetail($saleTaxIncluded, ['product_id' => $product->id, 'quantity' => 2, 'sub_total' => 2200, 'product_tax_amount' => 200]);

        $saleTaxExclusive = $this->makeSale($customer, ['is_tax_included' => false]);
        $this->makeSaleDetail($saleTaxExclusive, ['product_id' => $product->id, 'quantity' => 3, 'sub_total' => 3000, 'product_tax_amount' => 300]);

        $filter = new SaleByProductReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleByProductReportQueryService();
        $results = $queryService->build($filter)->get();

        $this->assertCount(1, $results);
        
        // Sold value = (2200 - 200) + 3000 = 5000
        $this->assertEquals(5000, $results[0]->sold_value);
        $this->assertEquals(5, $results[0]->sold_quantity);
        $this->assertEquals(1000, $results[0]->average_sales_value);
    }

    /** @test */
    public function it_handles_blank_product_code()
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['product_code' => '']);

        $sale = $this->makeSale($customer);
        $this->makeSaleDetail($sale, ['product_id' => $product->id, 'product_code' => '', 'quantity' => 1]);

        $filter = new SaleByProductReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleByProductReportQueryService();
        $results = $queryService->build($filter)->get();

        $this->assertCount(1, $results);
        $this->assertEquals('', $results[0]->product_code);
    }

    /** @test */
    public function it_renders_livewire_component_and_applies_filters()
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['product_code' => 'P-001', 'product_name' => 'TEST PRODUCT']);

        $sale = $this->makeSale($customer, ['date' => now()->format('Y-m-d')]);
        $this->makeSaleDetail($sale, ['product_id' => $product->id, 'product_code' => 'P-001', 'quantity' => 10, 'sub_total' => 10000]);

        session(['setting_id' => $this->setting->id]);

        Livewire::test(SaleByProductReport::class)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSee('P-001')
            ->assertSee('TEST PRODUCT')
            ->assertSee('10,00')
            ->assertSee('10.000') // Sold value
            ->assertSet('filterTriggered', true);
    }

    /** @test */
    public function it_can_reset_and_cancel_filters()
    {
        Livewire::test(SaleByProductReport::class)
            ->set('startDate', '2020-01-01')
            ->call('applyFilters')
            ->set('startDate', '2021-01-01')
            ->call('cancelFilters')
            ->assertSet('startDate', '2020-01-01')
            ->call('resetFilters')
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'));
    }

    /** @test */
    public function it_shows_empty_state_when_no_filters_applied_or_no_data()
    {
        Livewire::test(SaleByProductReport::class)
            ->assertSee('Silakan atur filter dan klik')
            ->assertDontSee('Total Keseluruhan')
            ->call('applyFilters')
            ->assertSee('Tidak ada data penjualan per produk');
    }

    /** @test */
    public function it_validates_snapshot_before_export()
    {
        Excel::fake();

        Livewire::test(SaleByProductReport::class)
            ->set('startDate', '2020-01-01')
            ->call('exportExcel') // Filter not applied yet
            ->assertDispatched('alert');

        // Apply filters to trigger snapshot
        Livewire::test(SaleByProductReport::class)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->format('Y-m-d'))
            ->call('applyFilters')
            ->call('exportExcel');

        Excel::assertDownloaded('sale_by_product_' . now()->format('Y-m-d') . '_' . now()->format('Y-m-d') . '.xlsx');
    }

    /** @test */
    public function export_has_correct_structure_and_headings()
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['product_code' => 'P-001', 'product_name' => 'Test Product']);

        $sale = $this->makeSale($customer);
        $this->makeSaleDetail($sale, ['product_id' => $product->id, 'quantity' => 10, 'sub_total' => 10000]);

        $filter = new SaleByProductReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleByProductReportQueryService();
        $query = $queryService->build($filter);

        $export = new SaleByProductReportExport($query, $filter, false);

        $this->assertEquals([
            'Kode Produk',
            'Nama Produk',
            'Kuantitas Terjual',
            'Kuantitas Retur',
            'Satuan',
            'Total Nilai terjual',
            'Total Nilai Retur',
            'Harga Penjualan Rata-rata',
        ], $export->headings());

        $arrayData = $export->array();
        $mapped = $arrayData[0]; // first row is data

        $this->assertEquals('P-001', $mapped[0]);
        $this->assertEquals($product->product_name, $mapped[1]);
        $this->assertEquals(10.0, $mapped[2]);
        $this->assertEquals(0.0, $mapped[3]);
        $this->assertEquals(10000.0, $mapped[5]);
        $this->assertEquals(1000.0, $mapped[7]);
        
        // Assert grand total is in last row
        $totalRow = $arrayData[1];
        $this->assertEquals('Total Keseluruhan', $totalRow[0]);
        $this->assertEquals(10000.0, $totalRow[5]);
        $this->assertEquals(0.0, $totalRow[6]);
    }

    /** @test */
    public function it_asserts_csv_has_totals_and_xlsx_has_metadata()
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $sale = $this->makeSale($customer);
        $this->makeSaleDetail($sale, ['product_id' => $product->id, 'quantity' => 1]);

        $filter = new SaleByProductReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );
        $queryService = new SaleByProductReportQueryService();
        $query = $queryService->build($filter);

        $exportCsv = new SaleByProductReportExport($query, $filter, true);
        $arrayCsv = $exportCsv->array();
        $this->assertEquals('Total Keseluruhan', end($arrayCsv)[0]);

        $exportXlsx = new SaleByProductReportExport($query, $filter, false);
        $events = $exportXlsx->registerEvents();
        $this->assertArrayHasKey(\Maatwebsite\Excel\Events\AfterSheet::class, $events);
    }

    /** @test */
    public function it_includes_unlinked_sale_returns()
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        // sale_id is nullable for unlinked returns
        $return = $this->makeSaleReturn($customer, ['date' => now()->format('Y-m-d'), 'sale_id' => null, 'status' => 'Completed']);
        $this->makeSaleReturnDetail($return, ['product_id' => $product->id, 'quantity' => 2, 'sub_total' => 2000]);

        $filter = new SaleByProductReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $queryService = new SaleByProductReportQueryService();
        $results = $queryService->build($filter)->get();

        $this->assertCount(1, $results);
        $this->assertEquals(2, $results[0]->return_quantity);
    }

    /** @test */
    public function it_filters_by_mencakup_semua_logic()
    {
        $customer = $this->makeCustomer();
        $product1 = $this->makeProduct();
        $product2 = $this->makeProduct();

        $sale1 = $this->makeSale($customer, ['date' => now()->format('Y-m-d')]);
        $this->makeSaleDetail($sale1, ['product_id' => $product1->id, 'quantity' => 10]);

        $sale2 = $this->makeSale($customer, ['date' => now()->format('Y-m-d')]);
        $this->makeSaleDetail($sale2, ['product_id' => $product2->id, 'quantity' => 5]);

        $tag1Id = \Illuminate\Support\Facades\DB::table('tags')->insertGetId(['name' => '{"en":"Tag1"}', 'slug' => '{"en":"tag1"}']);
        $tag2Id = \Illuminate\Support\Facades\DB::table('tags')->insertGetId(['name' => '{"en":"Tag2"}', 'slug' => '{"en":"tag2"}']);

        \Illuminate\Support\Facades\DB::table('taggables')->insert([
            ['tag_id' => $tag1Id, 'taggable_id' => $sale1->id, 'taggable_type' => 'Modules\Sale\Entities\Sale'],
            ['tag_id' => $tag2Id, 'taggable_id' => $sale1->id, 'taggable_type' => 'Modules\Sale\Entities\Sale'],
            ['tag_id' => $tag1Id, 'taggable_id' => $sale2->id, 'taggable_type' => 'Modules\Sale\Entities\Sale'],
        ]);

        $filter = new SaleByProductReportFilterData(
            startDate: now()->format('Y-m-d'),
            endDate: now()->format('Y-m-d'),
            scopeSettingId: $this->setting->id,
            tagIds: [$tag1Id, $tag2Id],
            tagLogic: 'Mencakup semua'
        );

        $queryService = new SaleByProductReportQueryService();
        $results = $queryService->build($filter)->get();

        $this->assertCount(1, $results);
        $this->assertEquals($product1->id, $results[0]->product_id);
    }
}
