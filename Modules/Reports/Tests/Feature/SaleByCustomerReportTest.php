<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\People\Entities\Customer;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Spatie\Tags\Tag;

class SaleByCustomerReportTest extends TestCase
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

    /** @test */
    public function it_can_render_the_sale_by_customer_report_page_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.sale-by-customer.index'));
        $response->assertStatus(200);
        $response->assertSee('Penjualan Per Customer');
    }

    /** @test */
    public function it_hides_the_report_from_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.sale-by-customer.index'));
        $response->assertStatus(403);
    }

    /** @test */
    public function it_shows_sale_by_customer_menu_for_authorized_users()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('reports.index'))
            ->assertStatus(200)
            ->assertSee('Penjualan Per Customer');
    }

    /** @test */
    public function it_defaults_start_and_end_date_to_current_month()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'));
    }

    /** @test */
    public function it_returns_one_row_per_sale_detail_for_a_single_sale()
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
        ]);
        $this->makeSaleDetail($sale, ['product_name' => 'Product A']);
        $this->makeSaleDetail($sale, ['product_name' => 'Product B']);
        $this->makeSaleDetail($sale, ['product_name' => 'Product C']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                return $sales->count() === 3;
            });
    }

    /** @test */
    public function it_hides_customers_without_matching_sale_details()
    {
        $customerWithSales = $this->makeCustomer('Customer A');
        $customerWithoutSales = $this->makeCustomer('Customer B');

        $sale = $this->makeSale($customerWithSales, [
            'date' => now()->startOfMonth()->format('Y-m-d'),
        ]);
        $this->makeSaleDetail($sale);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                $customerNames = $sales->pluck('customer_name')->unique()->map(fn($name) => strtoupper($name));
                \PHPUnit\Framework\Assert::assertTrue($customerNames->contains(strtoupper('Customer A')), 'Customer A not found. Found: ' . $customerNames->implode(', '));
                \PHPUnit\Framework\Assert::assertFalse($customerNames->contains(strtoupper('Customer B')), 'Customer B was found but should be hidden.');
                return true;
            });
    }

    /** @test */
    public function it_computes_running_totals_per_customer_using_sub_total_and_tax()
    {
        $customer = $this->makeCustomer('Customer A');
        $sale1 = $this->makeSale($customer, ['date' => '2026-05-01']);
        $sale2 = $this->makeSale($customer, ['date' => '2026-05-02']);

        // First sale detail
        $this->makeSaleDetail($sale1, ['sub_total' => 1000, 'product_tax_amount' => 110]);
        // Second sale detail
        $this->makeSaleDetail($sale2, ['sub_total' => 2000, 'product_tax_amount' => 0]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                // The query sorts by date desc.
                // Row 0 is sale2 (2026-05-02, sub_total 2000, tax 0)
                // Row 1 is sale1 (2026-05-01, sub_total 1000, tax 110)
                $rows = $sales->values(); // Use the original order
                \PHPUnit\Framework\Assert::assertCount(2, $rows, 'Expected 2 rows, found ' . $rows->count());

                \PHPUnit\Framework\Assert::assertEquals(2000, $rows[0]->sub_total);
                \PHPUnit\Framework\Assert::assertEquals(0, $rows[0]->previous_running_total);

                \PHPUnit\Framework\Assert::assertEquals(1000, $rows[1]->sub_total);
                \PHPUnit\Framework\Assert::assertEquals(2000, $rows[1]->previous_running_total);

                return true;
            });
    }

    /** @test */
    public function it_renders_pajak_row_when_product_tax_amount_is_greater_than_zero()
    {
        $customer = $this->makeCustomer('Customer A');
        $sale = $this->makeSale($customer, ['date' => '2026-05-01']);

        $detailWithTax = $this->makeSaleDetail($sale, ['sub_total' => 1000, 'product_tax_amount' => 110]);
        $detailWithoutTax = $this->makeSaleDetail($sale, ['sub_total' => 2000, 'product_tax_amount' => 0]);

        $mappedWithTax = \App\Services\Reports\SaleByCustomerReportQueryService::mapRows($detailWithTax, 0);
        \PHPUnit\Framework\Assert::assertCount(2, $mappedWithTax);
        \PHPUnit\Framework\Assert::assertFalse($mappedWithTax[0]['is_tax_row']);
        \PHPUnit\Framework\Assert::assertEquals(1000, $mappedWithTax[0]['Nominal tagihan']);
        \PHPUnit\Framework\Assert::assertTrue($mappedWithTax[1]['is_tax_row']);
        \PHPUnit\Framework\Assert::assertEquals(110, $mappedWithTax[1]['Nominal tagihan']);
        \PHPUnit\Framework\Assert::assertEquals('Pajak', $mappedWithTax[1]['Nama produk']);

        $mappedWithoutTax = \App\Services\Reports\SaleByCustomerReportQueryService::mapRows($detailWithoutTax, 0);
        \PHPUnit\Framework\Assert::assertCount(1, $mappedWithoutTax);
    }

    /** @test */
    public function it_filters_by_categories_correctly()
    {
        $category1 = Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C1', 'category_name' => 'Cat 1', 'created_by' => $this->user->id]);
        $category2 = Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C2', 'category_name' => 'Cat 2', 'created_by' => $this->user->id]);
        
        $product1 = Product::create(['product_name' => 'P1', 'product_code' => 'P1', 'product_cost' => 0, 'product_price' => 0, 'setting_id' => $this->setting->id, 'category_id' => $category1->id]);
        $product2 = Product::create(['product_name' => 'P2', 'product_code' => 'P2', 'product_cost' => 0, 'product_price' => 0, 'setting_id' => $this->setting->id, 'category_id' => $category2->id]);

        $customer = $this->makeCustomer();
        $sale = $this->makeSale($customer);
        
        $this->makeSaleDetail($sale, ['product_id' => $product1->id, 'product_name' => 'P1']);
        $this->makeSaleDetail($sale, ['product_id' => $product2->id, 'product_name' => 'P2']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('categoryIds', [$category1->id])
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                $names = $sales->pluck('product_name');
                \PHPUnit\Framework\Assert::assertTrue($names->contains('P1'));
                \PHPUnit\Framework\Assert::assertFalse($names->contains('P2'));
                return true;
            });
    }

    /** @test */
    public function it_filters_by_tags_using_mencakup_semua_and_salah_satu_logic()
    {
        $tag1 = Tag::findOrCreate('tag1');
        $tag2 = Tag::findOrCreate('tag2');

        $customer = $this->makeCustomer();
        
        $sale1 = $this->makeSale($customer, ['date' => '2026-05-01']);
        $sale1->attachTag($tag1);
        $this->makeSaleDetail($sale1, ['product_name' => 'Only Tag1']);
        
        $sale2 = $this->makeSale($customer, ['date' => '2026-05-02']);
        $sale2->attachTags([$tag1, $tag2]);
        $this->makeSaleDetail($sale2, ['product_name' => 'Both Tags']);

        // Salah satu logic
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('tagIds', [$tag1->id, $tag2->id])
            ->set('tagLogic', 'Salah satu')
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                \PHPUnit\Framework\Assert::assertEquals(2, $sales->count());
                return true;
            });
            
        // Mencakup semua logic
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('tagIds', [$tag1->id, $tag2->id])
            ->set('tagLogic', 'Mencakup semua')
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                \PHPUnit\Framework\Assert::assertEquals(1, $sales->count());
                \PHPUnit\Framework\Assert::assertEquals('BOTH TAGS', strtoupper($sales->first()->product_name));
                return true;
            });
    }

    /** @test */
    public function it_sorts_customers_by_total_sale_amount()
    {
        $customer1 = $this->makeCustomer('Customer Low');
        $customer2 = $this->makeCustomer('Customer High');

        $sale1 = $this->makeSale($customer1, ['date' => '2026-05-01']);
        $this->makeSaleDetail($sale1, ['sub_total' => 1000]);

        $sale2 = $this->makeSale($customer2, ['date' => '2026-05-01']);
        $this->makeSaleDetail($sale2, ['sub_total' => 5000]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('sortField', 'customer_total')
            ->set('sortDirection', 'desc')
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                $rows = $sales->values();
                // Row 0 should be Customer High because total is 5000 > 1000
                \PHPUnit\Framework\Assert::assertEquals('CUSTOMER HIGH', strtoupper($rows[0]->customer_name));
                \PHPUnit\Framework\Assert::assertEquals('CUSTOMER LOW', strtoupper($rows[1]->customer_name));
                return true;
            });
    }

    /** @test */
    public function it_has_export_buttons()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $response = $this->get(route('reports.sale-by-customer.index'));
        $response->assertSee('Excel');
        $response->assertSee('CSV');
    }

    /** @test */
    public function it_blocks_export_excel_before_filter_is_applied()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->call('exportExcel')
            ->assertDispatched('alert');
    }

    /** @test */
    public function it_blocks_export_csv_before_filter_is_applied()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->call('exportCsv')
            ->assertDispatched('alert');
    }

    /** @test */
    public function it_blocks_export_when_snapshot_is_stale()
    {
        $component = \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters');

        session()->forget('sale_by_customer_report_snapshot');

        $component->call('exportExcel')
            ->assertDispatched('alert');
    }

    /** @test */
    public function it_downloads_xlsx_after_applying_filters()
    {
        \Maatwebsite\Excel\Facades\Excel::fake();

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('sales_by_customer_2026-05-01_2026-05-31.xlsx');
    }

    /** @test */
    public function it_downloads_csv_after_applying_filters()
    {
        \Maatwebsite\Excel\Facades\Excel::fake();

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportCsv');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('sales_by_customer_2026-05-01_2026-05-31.csv');
    }

    /** @test */
    public function it_exports_same_row_count_as_filtered_display()
    {
        \Maatwebsite\Excel\Facades\Excel::fake();
        $customer = $this->makeCustomer('Customer A');
        $sale = $this->makeSale($customer, ['date' => '2026-05-15']);
        $this->makeSaleDetail($sale, ['product_name' => 'Prod 1']);
        $this->makeSaleDetail($sale, ['product_name' => 'Prod 2']);
        $this->makeSaleDetail($sale, ['product_name' => 'Prod 3']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('sales_by_customer_2026-05-01_2026-05-31.xlsx', function ($export) {
            return count($export->array()) === 6;
        });
    }
    /** @test */
    public function it_exports_correct_columns_and_includes_tax_rows()
    {
        \Maatwebsite\Excel\Facades\Excel::fake();
        $customer = $this->makeCustomer('Customer A');
        $sale = $this->makeSale($customer, ['date' => '2026-05-15']);
        $this->makeSaleDetail($sale, ['product_name' => 'Prod 1', 'sub_total' => 1000, 'product_tax_amount' => 110]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('sales_by_customer_2026-05-01_2026-05-31.xlsx', function ($export) {
            $headings = $export->headings();
            \PHPUnit\Framework\Assert::assertNotContains('Keterangan', $headings);
            \PHPUnit\Framework\Assert::assertEquals(['Customer', 'Tanggal', 'Transaksi', 'No', 'Produk', 'Kuantitas', 'Satuan', 'Harga Satuan', 'Jumlah Tagihan', 'Total'], $headings);

            $rows = $export->array();
            \PHPUnit\Framework\Assert::assertCount(5, $rows);

            $prodRow = $rows[1];
            \PHPUnit\Framework\Assert::assertEquals('PROD 1', $prodRow[4]);
            \PHPUnit\Framework\Assert::assertEquals(1000, $prodRow[8]);
            \PHPUnit\Framework\Assert::assertEquals(1000, $prodRow[9]);

            $taxRow = $rows[2];
            \PHPUnit\Framework\Assert::assertEquals('Pajak', $taxRow[4]);
            \PHPUnit\Framework\Assert::assertEquals(110, $taxRow[8]);
            \PHPUnit\Framework\Assert::assertEquals(1110, $taxRow[9]);

            $subtotalRow = $rows[3];
            \PHPUnit\Framework\Assert::assertEquals(1110, $subtotalRow[9]);

            $grandTotalRow = $rows[4];
            \PHPUnit\Framework\Assert::assertEquals(1110, $grandTotalRow[9]);

            return true;
        });
    }
    /** @test */
    public function it_export_respects_customer_filter()
    {
        \Maatwebsite\Excel\Facades\Excel::fake();
        $customer1 = $this->makeCustomer('Customer 1');
        $customer2 = $this->makeCustomer('Customer 2');
        
        $sale1 = $this->makeSale($customer1, ['date' => '2026-05-15']);
        $this->makeSaleDetail($sale1);
        
        $sale2 = $this->makeSale($customer2, ['date' => '2026-05-16']);
        $this->makeSaleDetail($sale2);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('customerIds', [$customer1->id])
            ->call('applyFilters')
            ->call('exportExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('sales_by_customer_2026-05-01_2026-05-31.xlsx', function ($export) use ($customer1) {
            $rows = $export->array();
            return count($rows) === 4 && $rows[0][0] === $customer1->customer_name;
        });
    }

    /** @test */
    public function it_hides_the_menu_item_from_unauthorized_users()
    {
        $unauthorizedUser = User::factory()->create();
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'reports.access']);
        $unauthorizedUser->givePermissionTo('reports.access');
        $this->actingAs($unauthorizedUser);
        session(['setting_id' => $this->setting->id]);

        $this->get(route('reports.index'))
            ->assertStatus(200)
            ->assertDontSee('Penjualan Per Customer');
    }

    /** @test */
    public function it_handles_period_presets_without_refreshing_rows_until_filtered()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->set('periodPreset', 'today')
            ->assertSet('startDate', now()->format('Y-m-d'))
            // Before applying filters, appliedFilters startDate is still the old one
            ->assertSet('appliedFilters.startDate', now()->startOfMonth()->format('Y-m-d'))
            ->call('applyFilters')
            // After applying filters, appliedFilters matches the new startDate
            ->assertSet('appliedFilters.startDate', now()->format('Y-m-d'));
    }

    /** @test */
    public function it_scopes_sales_to_the_current_setting()
    {
        $customer = $this->makeCustomer();
        $saleInSetting = $this->makeSale($customer, ['setting_id' => $this->setting->id]);
        $this->makeSaleDetail($saleInSetting, ['product_name' => 'In Setting']);

        $otherSetting = Setting::create([
            'company_name' => 'Other',
            'company_email' => 'other@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);
        $saleOtherSetting = $this->makeSale($customer, ['setting_id' => $otherSetting->id]);
        $this->makeSaleDetail($saleOtherSetting, ['product_name' => 'Other Setting']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                \PHPUnit\Framework\Assert::assertEquals(1, $sales->count());
                \PHPUnit\Framework\Assert::assertEquals('IN SETTING', strtoupper($sales->first()->product_name));
                return true;
            });
    }
    /** @test */
    public function it_groups_customer_rows_together_without_interleaving_when_dates_alternate()
    {
        $customerA = $this->makeCustomer('Customer A');
        $customerB = $this->makeCustomer('Customer B');

        // Alternating dates: B, A, B
        $saleB1 = $this->makeSale($customerB, ['date' => '2026-05-01']);
        $this->makeSaleDetail($saleB1, ['product_name' => 'B 01']);

        $saleA1 = $this->makeSale($customerA, ['date' => '2026-05-02']);
        $this->makeSaleDetail($saleA1, ['product_name' => 'A 02']);

        $saleB2 = $this->makeSale($customerB, ['date' => '2026-05-03']);
        $this->makeSaleDetail($saleB2, ['product_name' => 'B 03']);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->set('sortField', 'date')
            ->set('sortDirection', 'desc')
            ->call('applyFilters')
            ->assertViewHas('sales', function ($sales) {
                $rows = $sales->values();
                \PHPUnit\Framework\Assert::assertCount(3, $rows);

                // Both B rows must be adjacent, and A must be by itself.
                // With max date DESC:
                // B's max date is 2026-05-03.
                // A's max date is 2026-05-02.
                // So Customer B group comes first, then Customer A group.
                // Within B, dates are DESC: 03 then 01.

                \PHPUnit\Framework\Assert::assertEquals('CUSTOMER B', strtoupper($rows[0]->customer_name));
                \PHPUnit\Framework\Assert::assertEquals('B 03', $rows[0]->product_name);

                \PHPUnit\Framework\Assert::assertEquals('CUSTOMER B', strtoupper($rows[1]->customer_name));
                \PHPUnit\Framework\Assert::assertEquals('B 01', $rows[1]->product_name);

                \PHPUnit\Framework\Assert::assertEquals('CUSTOMER A', strtoupper($rows[2]->customer_name));
                \PHPUnit\Framework\Assert::assertEquals('A 02', $rows[2]->product_name);

                return true;
            });
    }

    /** @test */
    public function it_computes_running_totals_correctly_on_page_two_including_tax()
    {
        $customer = $this->makeCustomer('Customer A');
        
        // Create 20 sales so page 2 has 5 items (default perPage is 15)
        for ($i = 1; $i <= 20; $i++) {
            $sale = $this->makeSale($customer, ['date' => '2026-05-01']);
            // Page 1 contains rows 20 down to 6. They all have sub_total = 100, tax = 10.
            // So the correct running total at the start of page 2 (after page 1) MUST be 15 * 110 = 1650.
            $subTotal = ($i === 1) ? 1000 : 100;
            $taxAmount = ($i === 1) ? 100 : 10;
            $this->makeSaleDetail($sale, ['sub_total' => $subTotal, 'product_tax_amount' => $taxAmount]);
        }

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->call('setPage', 2)
            ->assertViewHas('sales', function ($sales) {
                $rows = $sales->values();
                \PHPUnit\Framework\Assert::assertCount(5, $rows);
                
                // The first 15 items (rows 20 down to 6) had sub_total 100, tax 10, so running total before page 2 is 1650.
                // The first item on page 2 (row 5) should have a previous_running_total of 1650.
                \PHPUnit\Framework\Assert::assertEquals(1650, $rows[0]->previous_running_total);
                
                // The last item on page 2 (row 1) has sub_total 1000, tax 100.
                // Its previous_running_total should be 1650 + 4*110 = 2090.
                \PHPUnit\Framework\Assert::assertEquals(2090, $rows[4]->previous_running_total);
                
                return true;
            });
    }

    /** @test */
    public function it_restores_date_filters_when_cancel_filters_is_called()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('periodPreset', 'this_month')
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('applyFilters')
            ->set('periodPreset', 'today')
            ->set('startDate', '2026-06-01') // Unapplied change
            ->call('cancelFilters')
            ->assertSet('periodPreset', 'this_month') // Restored from appliedFilters
            ->assertSet('startDate', '2026-05-01'); // Restored from appliedFilters
    }

    /** @test */
    public function it_resets_date_filters_to_current_month_when_reset_filters_is_called()
    {
        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\SaleByCustomerReport::class)
            ->set('periodPreset', 'this_month')
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-31')
            ->call('resetFilters')
            ->assertSet('periodPreset', '')
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'));
    }
}
