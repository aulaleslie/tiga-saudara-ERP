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

    /** @test */
    public function it_asserts_multi_token_search_matches_non_contiguous_words()
    {
        $p1 = $this->makeProduct(['product_name' => 'ALFA INK EPSON BLACK']);
        $p2 = $this->makeProduct(['product_name' => 'CANON INK BLACK']);

        session(['setting_id' => $this->setting->id]);

        Livewire::test(SaleByProductReport::class)
            ->set('productSearch', 'alf bla')
            ->assertSet('productOptions', function ($options) use ($p1, $p2) {
                $ids = collect($options)->pluck('id')->toArray();
                return in_array($p1->id, $ids) && !in_array($p2->id, $ids);
            });
    }

    /** @test */
    public function it_asserts_search_returns_empty_when_one_token_matches_nothing()
    {
        $this->makeProduct(['product_name' => 'ALFA INK EPSON BLACK']);

        session(['setting_id' => $this->setting->id]);

        Livewire::test(SaleByProductReport::class)
            ->set('productSearch', 'alfa xyznomatch')
            ->assertSet('productOptions', []);
    }

    /** @test */
    public function it_asserts_token_order_does_not_affect_matched_set()
    {
        $p1 = $this->makeProduct(['product_name' => 'ALFA INK EPSON BLACK']);

        session(['setting_id' => $this->setting->id]);

        $component1 = Livewire::test(SaleByProductReport::class)
            ->set('productSearch', 'epson alfa');
        $opts1 = collect($component1->get('productOptions'))->pluck('id')->toArray();

        $component2 = Livewire::test(SaleByProductReport::class)
            ->set('productSearch', 'alfa epson');
        $opts2 = collect($component2->get('productOptions'))->pluck('id')->toArray();

        $this->assertEquals($opts1, $opts2);
        $this->assertContains($p1->id, $opts1);
    }

    /** @test */
    public function it_asserts_product_is_found_by_product_code()
    {
        $p1 = $this->makeProduct([
            'product_name' => 'SOME INK ITEM',
            'product_code' => 'SKU-XYZ-9988',
        ]);

        session(['setting_id' => $this->setting->id]);

        Livewire::test(SaleByProductReport::class)
            ->set('productSearch', '9988')
            ->assertSet('productOptions', function ($options) use ($p1) {
                return collect($options)->contains('id', $p1->id);
            });
    }

    /** @test */
    public function it_asserts_tokens_across_name_and_code_together_select_product()
    {
        $p1 = $this->makeProduct([
            'product_name' => 'ALFA INK EPSON',
            'product_code' => 'SKU-RED-11',
        ]);

        session(['setting_id' => $this->setting->id]);

        Livewire::test(SaleByProductReport::class)
            ->set('productSearch', 'alfa RED-11')
            ->assertSet('productOptions', function ($options) use ($p1) {
                return collect($options)->contains('id', $p1->id);
            });
    }

    /** @test */
    public function it_asserts_product_options_carry_product_code()
    {
        $p1 = $this->makeProduct([
            'product_name' => 'ALFA INK EPSON',
            'product_code' => 'SKU-001',
        ]);

        session(['setting_id' => $this->setting->id]);

        Livewire::test(SaleByProductReport::class)
            ->set('productSearch', 'alfa')
            ->assertSet('productOptions', function ($options) use ($p1) {
                $option = collect($options)->firstWhere('id', $p1->id);
                return $option && isset($option['product_code']) && $option['product_code'] === 'SKU-001';
            });
    }

    /** @test */
    public function it_asserts_minimum_search_length_suppresses_options()
    {
        $this->makeProduct(['product_name' => 'ALFA INK EPSON']);

        session(['setting_id' => $this->setting->id]);

        Livewire::test(SaleByProductReport::class)
            ->set('productSearch', 'a')
            ->assertSet('productOptions', []);
    }

    /** @test */
    public function it_asserts_select_all_matching_selects_beyond_displayed_limit()
    {
        $createdIds = [];
        for ($i = 1; $i <= 15; $i++) {
            $p = $this->makeProduct([
                'product_name' => "BULKTEST PRODUCT {$i}",
                'product_code' => "BT-{$i}",
            ]);
            $createdIds[] = $p->id;
        }

        session(['setting_id' => $this->setting->id]);

        $test = Livewire::test(SaleByProductReport::class)
            ->set('productSearch', 'bulktest')
            ->call('selectAllMatchingProducts');

        $this->assertCount(10, $test->get('productOptions'));
        $this->assertEqualsCanonicalizing($createdIds, $test->get('productIds'));
    }

    /** @test */
    public function it_asserts_select_all_matching_merges_without_duplicates()
    {
        $p1 = $this->makeProduct(['product_name' => 'MERGETEST PROD 1']);
        $p2 = $this->makeProduct(['product_name' => 'MERGETEST PROD 2']);
        $pOther = $this->makeProduct(['product_name' => 'OTHER PROD']);

        session(['setting_id' => $this->setting->id]);

        $test = Livewire::test(SaleByProductReport::class)
            ->set('productIds', [$pOther->id, $p1->id])
            ->set('productLabels', [
                $pOther->id => 'OTHER PROD',
                $p1->id => 'MERGETEST PROD 1',
            ])
            ->set('productSearch', 'mergetest')
            ->call('selectAllMatchingProducts');

        $productIds = $test->get('productIds');
        $this->assertCount(3, $productIds);
        $this->assertEqualsCanonicalizing([$pOther->id, $p1->id, $p2->id], $productIds);
    }

    /** @test */
    public function it_asserts_ceiling_truncates_selection_and_dispatches_alert()
    {
        Product::query()->delete();
        $records = [];
        for ($i = 1; $i <= 505; $i++) {
            $records[] = [
                'setting_id' => $this->setting->id,
                'product_name' => "CEILINGTEST PROD {$i}",
                'product_code' => "CT-{$i}",
                'product_cost' => 500,
                'product_price' => 1000,
                'product_quantity' => 10,
                'product_unit' => 'Pcs',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Product::insert($records);

        session(['setting_id' => $this->setting->id]);

        Livewire::test(SaleByProductReport::class)
            ->set('productSearch', 'ceilingtest')
            ->call('selectAllMatchingProducts')
            ->assertDispatched('alert', ['type' => 'warning', 'message' => 'Pencarian menghasilkan 505 produk. Hanya 500 produk pertama yang dipilih secara otomatis.'])
            ->assertSet('productIds', function ($ids) {
                return count($ids) === 500;
            });
    }

    /** @test */
    public function it_asserts_select_all_matching_is_no_op_below_minimum_search_length()
    {
        $this->makeProduct(['product_name' => 'TEST PRODUCT']);

        session(['setting_id' => $this->setting->id]);

        Livewire::test(SaleByProductReport::class)
            ->set('productSearch', 't')
            ->call('selectAllMatchingProducts')
            ->assertSet('productIds', []);
    }

    /** @test */
    public function it_asserts_report_applied_after_bulk_selection_reflects_every_selected_product()
    {
        $customer = $this->makeCustomer('Bulk Customer');
        $p1 = $this->makeProduct(['product_name' => 'BULKREP P1']);
        $p2 = $this->makeProduct(['product_name' => 'BULKREP P2']);
        $pUnselected = $this->makeProduct(['product_name' => 'UNSELECTED P3']);

        $sale = $this->makeSale($customer);
        $this->makeSaleDetail($sale, ['product_id' => $p1->id, 'quantity' => 2, 'unit_price' => 1000, 'sub_total' => 2000]);
        $this->makeSaleDetail($sale, ['product_id' => $p2->id, 'quantity' => 3, 'unit_price' => 1000, 'sub_total' => 3000]);
        $this->makeSaleDetail($sale, ['product_id' => $pUnselected->id, 'quantity' => 5, 'unit_price' => 1000, 'sub_total' => 5000]);

        session(['setting_id' => $this->setting->id]);

        Livewire::test(SaleByProductReport::class)
            ->set('productSearch', 'bulkrep')
            ->call('selectAllMatchingProducts')
            ->call('applyFilters')
            ->assertViewHas('products', function ($paginator) use ($p1, $p2, $pUnselected) {
                $ids = collect($paginator->items())->pluck('product_id')->toArray();
                return in_array($p1->id, $ids) && in_array($p2->id, $ids) && !in_array($pUnselected->id, $ids);
            });
    }
}
