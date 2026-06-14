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
        $report = $service->generate($this->setting->id, '2023-05-01', '2023-05-31');

        $this->assertEquals(100000, $report->salesTotal);
        $this->assertEquals(10000, $report->saleReturnsTotal);
        $this->assertEquals(90000, $report->netRevenue);
        
        $this->assertEquals(50000, $report->purchasesTotal);
        $this->assertEquals(5000, $report->purchaseReturnsTotal);
        $this->assertEquals(20000, $report->expensesTotal);
        $this->assertEquals(65000, $report->totalCost);
        
        $this->assertEquals(25000, $report->profitLoss);
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
            ->assertDontSee('Beban Pokok Pendapatan');
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
            $this->assertStringContainsString('Biaya', $contents);
            $this->assertStringContainsString('Pembelian', $contents);
            $this->assertStringContainsString('Retur Pembelian', $contents);
            $this->assertStringContainsString('Beban', $contents);
            $this->assertStringContainsString('Laba (Rugi)', $contents);

            $this->assertStringNotContainsString('Laba Kotor', $contents);
            $this->assertStringNotContainsString('Beban Pokok Pendapatan', $contents);

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
        $report = $service->generate($this->setting->id, '2023-05-01', '2023-05-31');

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
        $report = $service->generate($this->setting->id, '2023-05-01', '2023-05-31');

        // Expected: sale 100k (included despite RETURNED status), return 100k
        // Net revenue = 100k - 100k = 0 (breaks even)
        $this->assertEquals(100000, $report->salesTotal);
        $this->assertEquals(100000, $report->saleReturnsTotal);
        $this->assertEquals(0, $report->netRevenue);
    }

    public function test_returned_purchases_are_included_in_cost_calculation()
    {
        // Purchase with status RETURNED_PARTIALLY should still be counted as cost
        Purchase::forceCreate(['setting_id' => $this->setting->id, 'status' => Purchase::STATUS_RETURNED_PARTIALLY, 'total_amount' => 50000, 'paid_amount' => 50000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'due_date' => '2023-05-15', 'reference' => 'P2', 'supplier_name' => 'S']);

        // Return of that purchase
        PurchaseReturn::forceCreate(['setting_id' => $this->setting->id, 'status' => 'Completed', 'total_amount' => 5000, 'paid_amount' => 5000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-16', 'reference' => 'PR2', 'supplier_name' => 'S']);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate($this->setting->id, '2023-05-01', '2023-05-31');

        // Expected: purchase 50k (included despite RETURNED_PARTIALLY status), return 5k
        // Total cost = 50k - 5k = 45k
        $this->assertEquals(50000, $report->purchasesTotal);
        $this->assertEquals(5000, $report->purchaseReturnsTotal);
        $this->assertEquals(45000, $report->totalCost);
    }

    public function test_fully_returned_purchases_are_included_in_cost_calculation()
    {
        // Purchase with status RETURNED should still be counted as cost
        Purchase::forceCreate(['setting_id' => $this->setting->id, 'status' => Purchase::STATUS_RETURNED, 'total_amount' => 50000, 'paid_amount' => 50000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'due_date' => '2023-05-15', 'reference' => 'P3', 'supplier_name' => 'S']);

        // Full return of that purchase
        PurchaseReturn::forceCreate(['setting_id' => $this->setting->id, 'status' => 'Completed', 'total_amount' => 50000, 'paid_amount' => 50000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-16', 'reference' => 'PR3', 'supplier_name' => 'S']);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate($this->setting->id, '2023-05-01', '2023-05-31');

        // Expected: purchase 50k (included despite RETURNED status), return 50k
        // Total cost = 50k - 50k = 0
        $this->assertEquals(50000, $report->purchasesTotal);
        $this->assertEquals(50000, $report->purchaseReturnsTotal);
        $this->assertEquals(0, $report->totalCost);
    }

    public function test_partially_dispatched_sales_are_excluded_from_report()
    {
        // Sale with DISPATCHED_PARTIALLY status should NOT be counted (incomplete)
        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED_PARTIALLY, 'total_amount' => 100000, 'paid_amount' => 50000, 'due_amount' => 50000, 'payment_status' => 'Partial', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S6', 'customer_name' => 'C']);

        // Fully dispatched sale for comparison
        Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 50000, 'paid_amount' => 50000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S7', 'customer_name' => 'C']);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate($this->setting->id, '2023-05-01', '2023-05-31');

        // Expected: only fully dispatched sale (50k) is counted, partially dispatched (100k) is excluded
        $this->assertEquals(50000, $report->salesTotal);
        $this->assertEquals(0, $report->saleReturnsTotal);
        $this->assertEquals(50000, $report->netRevenue);
    }

    public function test_partially_received_purchases_are_excluded_from_report()
    {
        // Purchase with RECEIVED_PARTIALLY status should NOT be counted (incomplete)
        Purchase::forceCreate(['setting_id' => $this->setting->id, 'status' => Purchase::STATUS_RECEIVED_PARTIALLY, 'total_amount' => 80000, 'paid_amount' => 40000, 'due_amount' => 40000, 'payment_status' => 'Partial', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'due_date' => '2023-05-15', 'reference' => 'P4', 'supplier_name' => 'S']);

        // Fully received purchase for comparison
        Purchase::forceCreate(['setting_id' => $this->setting->id, 'status' => Purchase::STATUS_RECEIVED, 'total_amount' => 40000, 'paid_amount' => 40000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'due_date' => '2023-05-15', 'reference' => 'P5', 'supplier_name' => 'S']);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate($this->setting->id, '2023-05-01', '2023-05-31');

        // Expected: only fully received purchase (40k) is counted, partially received (80k) is excluded
        $this->assertEquals(40000, $report->purchasesTotal);
        $this->assertEquals(0, $report->purchaseReturnsTotal);
        $this->assertEquals(40000, $report->totalCost);
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
}
