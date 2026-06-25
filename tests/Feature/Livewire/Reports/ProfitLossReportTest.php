<?php

namespace Tests\Feature\Livewire\Reports;

use App\Exports\ProfitLossReportExport;
use App\Livewire\Reports\ProfitLossReport;
use App\Services\Reports\OperationalProfitLossReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Expense\Entities\Expense;
use Modules\Purchase\Entities\Purchase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class ProfitLossReportTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'reports.access']);

        $currency = Currency::factory()->create(['code' => 'IDR']);
        $this->setting = Setting::factory()->create(['default_currency_id' => $currency->id]);
        session(['setting_id' => $this->setting->id]);
    }

    public function test_operational_profit_loss_service_query_logic_and_value_object()
    {
        $category = \Modules\Expense\Entities\ExpenseCategory::forceCreate(['category_name' => 'Cat 1', 'category_description' => 'Test']);
        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S1', 'customer_name' => 'C']);
        SaleReturn::forceCreate(['setting_id' => $this->setting->id, 'status' => 'Completed', 'total_amount' => 10000, 'paid_amount' => 10000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'SR1', 'customer_name' => 'C']);
        Purchase::forceCreate(['setting_id' => $this->setting->id, 'status' => Purchase::STATUS_RECEIVED, 'total_amount' => 50000, 'paid_amount' => 50000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'due_date' => '2023-05-15', 'reference' => 'P1', 'supplier_name' => 'S']);
        PurchaseReturn::forceCreate(['setting_id' => $this->setting->id, 'status' => 'Completed', 'total_amount' => 5000, 'paid_amount' => 5000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'PR1', 'supplier_name' => 'S']);
        Expense::forceCreate(['setting_id' => $this->setting->id, 'status' => Expense::STATUS_APPROVED, 'amount' => 20000, 'date' => '2023-05-15', 'archived_at' => null, 'reference' => 'E1', 'category_id' => $category->id, 'details' => 'e']);

        // Outside dates
        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 99900000, 'paid_amount' => 99900000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-06-15', 'reference' => 'S2', 'customer_name' => 'C']);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id], '2023-05-01', '2023-05-31');

        $this->assertEquals(100000, $report->salesTotal);
        $this->assertEquals(10000, $report->saleReturnsTotal);
        $this->assertEquals(90000, $report->netRevenue);
        
        $this->assertEquals(0, $report->salesCostTotal);
        $this->assertEquals(0, $report->saleReturnCostTotal);
        $this->assertEquals(20000, $report->expensesTotal);
        $this->assertEquals(20000, $report->totalCost);
        
        $this->assertEquals(70000, $report->profitLoss);
        $this->assertEquals('IDR', $report->currencyCode);
    }

    public function test_livewire_component_passes_correct_properties_and_blade_formats()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        $category = \Modules\Expense\Entities\ExpenseCategory::forceCreate(['category_name' => 'Cat 2', 'category_description' => 'Test']);
        // Negative profit
        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 10000, 'paid_amount' => 10000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S3', 'customer_name' => 'C']);
        Expense::forceCreate(['setting_id' => $this->setting->id, 'status' => Expense::STATUS_APPROVED, 'amount' => 50000, 'date' => '2023-05-15', 'archived_at' => null, 'reference' => 'E2', 'category_id' => $category->id, 'details' => 'e']);

        Livewire::actingAs($user)
            ->test(ProfitLossReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->call('generateReport')
            ->assertViewHas('report', function ($report) {
                return $report->profitLoss == -40000;
            })
            ->assertSee('(dalam IDR)')
            ->assertSee('(' . format_currency(40000) . ')')
            ->assertDontSee('Laba Kotor')
            ->assertSee('Beban Pokok Pendapatan');
    }

    public function test_excel_export_payload_structure()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Excel::fake();

        Livewire::actingAs($user)
            ->test(ProfitLossReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->call('exportExcel');

        Excel::assertDownloaded('profit_loss_01-05-2023_31-05-2023.xlsx', function (ProfitLossReportExport $export) {
            $array = $export->array();
            $contents = json_encode($array);

            $this->assertStringContainsString('Pendapatan', $contents);
            $this->assertStringContainsString('Penjualan', $contents);
            $this->assertStringContainsString('Retur Penjualan', $contents);
            $this->assertStringContainsString('Beban Pokok Pendapatan', $contents);
            $this->assertStringContainsString('Harga Pokok Penjualan', $contents);
            $this->assertStringContainsString('Koreksi HPP (Retur)', $contents);
            $this->assertStringContainsString('Biaya Operasional', $contents);
            $this->assertStringContainsString('Beban', $contents);
            $this->assertStringContainsString('Laba (Rugi)', $contents);

            $this->assertStringNotContainsString('Laba Kotor', $contents);
            $this->assertStringNotContainsString('Pembelian', $contents);
            $this->assertStringNotContainsString('Retur Pembelian', $contents);

            return true;
        });
    }

    public function test_returned_sales_are_included_in_revenue_calculation()
    {
        // Sale with status RETURNED_PARTIALLY should still be counted as revenue
        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_RETURNED_PARTIALLY, 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S4', 'customer_name' => 'C']);

        // Return of that sale
        SaleReturn::forceCreate(['setting_id' => $this->setting->id, 'status' => 'Completed', 'total_amount' => 10000, 'paid_amount' => 10000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-16', 'reference' => 'SR2', 'customer_name' => 'C']);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id], '2023-05-01', '2023-05-31');

        // Expected: sale 100k (included despite RETURNED_PARTIALLY status), return 10k
        // Net revenue = 100k - 10k = 90k
        $this->assertEquals(100000, $report->salesTotal);
        $this->assertEquals(10000, $report->saleReturnsTotal);
        $this->assertEquals(90000, $report->netRevenue);
    }

    public function test_fully_returned_sales_are_included_in_revenue_calculation()
    {
        // Sale with status RETURNED should still be counted as revenue
        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_RETURNED, 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S5', 'customer_name' => 'C']);

        // Full return of that sale
        SaleReturn::forceCreate(['setting_id' => $this->setting->id, 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-16', 'reference' => 'SR3', 'customer_name' => 'C']);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id], '2023-05-01', '2023-05-31');

        // Expected: sale 100k (included despite RETURNED status), return 100k
        // Net revenue = 100k - 100k = 0 (breaks even)
        $this->assertEquals(100000, $report->salesTotal);
        $this->assertEquals(100000, $report->saleReturnsTotal);
        $this->assertEquals(0, $report->netRevenue);
    }


    public function test_access_gate_denies_without_reports_access_permission()
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)->get(route('profit-loss-report.index'));

        $this->assertEquals(403, $response->status());
    }

    public function test_access_gate_allows_with_reports_access_permission()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');
        session(['setting_id' => $this->setting->id]);

        $response = $this->actingAs($user)->get(route('profit-loss-report.index'));

        $this->assertEquals(200, $response->status());
    }

    // 1.1: Service-level tests for default/current setting scope
    public function test_service_default_scope_includes_only_current_setting()
    {
        $otherCurrency = Currency::factory()->create(['code' => 'USD']);
        $otherSetting = Setting::factory()->create(['default_currency_id' => $otherCurrency->id]);

        $category = \Modules\Expense\Entities\ExpenseCategory::forceCreate(['category_name' => 'Cat', 'category_description' => 'Test']);

        // Data in current setting
        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S1', 'customer_name' => 'C']);

        // Data in other setting (should be excluded)
        Sale::forceCreate(['setting_id' => $otherSetting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 500000, 'paid_amount' => 500000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S2', 'customer_name' => 'C']);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id], '2023-05-01', '2023-05-31');

        $this->assertEquals(100000, $report->salesTotal);
        $this->assertNotEquals(500000, $report->salesTotal);
    }

    // 1.2: Service-level tests for selected multiple setting IDs
    public function test_service_selected_multiple_settings_includes_only_those_settings()
    {
        $currency = Currency::factory()->create(['code' => 'IDR']);
        $setting2 = Setting::factory()->create(['default_currency_id' => $currency->id]);
        $setting3 = Setting::factory()->create(['default_currency_id' => $currency->id]);

        $category = \Modules\Expense\Entities\ExpenseCategory::forceCreate(['category_name' => 'Cat', 'category_description' => 'Test']);

        // Data in setting 1
        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S1', 'customer_name' => 'C']);

        // Data in setting 2
        Sale::forceCreate(['setting_id' => $setting2->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 200000, 'paid_amount' => 200000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S2', 'customer_name' => 'C']);

        // Data in setting 3 (should be excluded)
        Sale::forceCreate(['setting_id' => $setting3->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 300000, 'paid_amount' => 300000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S3', 'customer_name' => 'C']);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id, $setting2->id], '2023-05-01', '2023-05-31');

        $this->assertEquals(300000, $report->salesTotal); // 100k + 200k
        $this->assertNotEquals(600000, $report->salesTotal); // Should not include setting 3
    }

    // 1.3: Livewire component access and scope selector visibility
    public function test_livewire_reports_access_users_can_see_scope_selector()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Livewire::actingAs($user)
            ->test(ProfitLossReport::class)
            ->assertViewHas('availableSettings')
            ->assertSee('Perusahaan');
    }

    public function test_livewire_unauthorized_users_denied_access()
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)->get(route('profit-loss-report.index'))
            ->assertStatus(403);
    }

    // 1.4: Export parity tests
    public function test_export_receives_same_selected_settings_as_screen()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        $currency = Currency::factory()->create(['code' => 'IDR']);
        $setting2 = Setting::factory()->create(['default_currency_id' => $currency->id]);

        // Third setting to ensure we have >2 but not all selected
        $setting3 = Setting::factory()->create(['default_currency_id' => $currency->id]);

        $category = \Modules\Expense\Entities\ExpenseCategory::forceCreate(['category_name' => 'Cat', 'category_description' => 'Test']);

        // Sales in each setting
        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S1', 'customer_name' => 'C']);
        Sale::forceCreate(['setting_id' => $setting2->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 200000, 'paid_amount' => 200000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S2', 'customer_name' => 'C']);

        Excel::fake();

        Livewire::actingAs($user)
            ->test(ProfitLossReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->set('selectedSettingIds', [$this->setting->id, $setting2->id])
            ->call('generateReport')
            ->assertViewHas('report', function ($report) {
                return $report->salesTotal == 300000; // Both settings
            })
            ->call('exportExcel');

        Excel::assertDownloaded('profit_loss_01-05-2023_31-05-2023.xlsx', function (ProfitLossReportExport $export) {
            $array = $export->array();
            $contents = json_encode($array);

            // Should contain multi-company reference
            $this->assertStringContainsString('Beberapa Perusahaan', $contents);

            return true;
        });
    }

    // 1.5: Header/scope-label tests
    public function test_header_shows_single_company_name()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S1', 'customer_name' => 'C']);

        Livewire::actingAs($user)
            ->test(ProfitLossReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->set('selectedSettingIds', [$this->setting->id])
            ->call('generateReport')
            ->assertSee($this->setting->company_name);
    }

    public function test_header_shows_partial_multi_company_scope()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        $currency = Currency::factory()->create(['code' => 'IDR']);
        $setting2 = Setting::factory()->create(['default_currency_id' => $currency->id]);
        Setting::factory()->create(['default_currency_id' => $currency->id]); // Third setting not selected

        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S1', 'customer_name' => 'C']);

        Livewire::actingAs($user)
            ->test(ProfitLossReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->set('selectedSettingIds', [$this->setting->id, $setting2->id])
            ->call('generateReport')
            ->assertSee('Beberapa Perusahaan');
    }

    public function test_header_shows_all_companies_scope()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        $currency = Currency::factory()->create(['code' => 'IDR']);
        $setting2 = Setting::factory()->create(['default_currency_id' => $currency->id]);

        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S1', 'customer_name' => 'C']);

        Livewire::actingAs($user)
            ->test(ProfitLossReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->set('selectedSettingIds', [$this->setting->id, $setting2->id])
            ->call('generateReport')
            ->assertSee('Semua Perusahaan');
    }

    public function test_invalid_setting_ids_are_filtered_out()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        $currency = Currency::factory()->create(['code' => 'IDR']);
        $setting2 = Setting::factory()->create(['default_currency_id' => $currency->id]);

        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S1', 'customer_name' => 'C']);
        Sale::forceCreate(['setting_id' => $setting2->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 200000, 'paid_amount' => 200000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S2', 'customer_name' => 'C']);

        // Try to pass invalid ID 999999 along with valid IDs
        Livewire::actingAs($user)
            ->test(ProfitLossReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->set('selectedSettingIds', [$this->setting->id, $setting2->id, 999999])
            ->call('generateReport')
            ->assertViewHas('report', function ($report) {
                // Should include both valid settings only
                return $report->salesTotal == 300000;
            })
            ->assertSee('Semua Perusahaan'); // Should recognize all valid settings are selected
    }

    public function test_export_filters_invalid_setting_ids()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        $currency = Currency::factory()->create(['code' => 'IDR']);
        $setting2 = Setting::factory()->create(['default_currency_id' => $currency->id]);

        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S1', 'customer_name' => 'C']);

        Excel::fake();

        Livewire::actingAs($user)
            ->test(ProfitLossReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->set('selectedSettingIds', [$this->setting->id, 999999])
            ->call('generateReport')
            ->call('exportExcel');

        Excel::assertDownloaded('profit_loss_01-05-2023_31-05-2023.xlsx', function (ProfitLossReportExport $export) {
            $array = $export->array();
            $contents = json_encode($array);

            // Should show single company name, not "Beberapa Perusahaan" or "Semua Perusahaan"
            $this->assertStringContainsString($this->setting->company_name, $contents);

            return true;
        });
    }
}
