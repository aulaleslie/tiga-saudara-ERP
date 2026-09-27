<?php

namespace Tests\Feature\Livewire\Reports;

use App\Exports\OperationalCashFlowReportExport;
use App\Livewire\Reports\OperationalCashFlowReport;
use App\Services\Reports\OperationalCashFlowReportFilterData;
use App\Services\Reports\OperationalCashFlowReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Expense\Entities\Expense;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class OperationalCashFlowReportTest extends TestCase
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

    public function test_operational_cash_flow_service_query_logic()
    {
        $category = \Modules\Expense\Entities\ExpenseCategory::forceCreate(['category_name' => 'Cat 1', 'category_description' => 'Test']);
        
        // Prior to start date (opening cash)
        $saleOpening = Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-04-15', 'reference' => 'S1', 'customer_name' => 'C']);
        SalePayment::forceCreate(['sale_id' => $saleOpening->id, 'amount' => 100000, 'date' => '2023-04-15', 'reference' => 'SP1', 'payment_method' => 'Cash']);
        
        $expenseOpening = Expense::forceCreate(['setting_id' => $this->setting->id, 'status' => Expense::STATUS_APPROVED, 'amount' => 20000, 'date' => '2023-04-15', 'archived_at' => null, 'reference' => 'E1', 'category_id' => $category->id, 'details' => 'e']); 

        // In period (1-31 May 2023)
        $salePeriod = Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'S2', 'customer_name' => 'C']);
        SalePayment::forceCreate(['sale_id' => $salePeriod->id, 'amount' => 150000, 'date' => '2023-05-15', 'reference' => 'SP2', 'payment_method' => 'Cash']);

        $purchasePeriod = Purchase::forceCreate(['setting_id' => $this->setting->id, 'status' => Purchase::STATUS_RECEIVED, 'total_amount' => 50000, 'paid_amount' => 50000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'due_date' => '2023-05-15', 'reference' => 'P1', 'supplier_name' => 'S']);
        PurchasePayment::forceCreate(['purchase_id' => $purchasePeriod->id, 'amount' => 50000, 'date' => '2023-05-15', 'reference' => 'PP1', 'payment_method' => 'Cash']);

        $saleReturn = SaleReturn::forceCreate(['setting_id' => $this->setting->id, 'status' => 'Completed', 'total_amount' => 10000, 'paid_amount' => 10000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'SR1', 'customer_name' => 'C']);
        SaleReturnPayment::forceCreate(['sale_return_id' => $saleReturn->id, 'amount' => 10000, 'date' => '2023-05-15', 'reference' => 'SRP1', 'payment_method' => 'Cash']);

        $purchaseReturn = PurchaseReturn::forceCreate(['setting_id' => $this->setting->id, 'status' => 'Completed', 'total_amount' => 5000, 'paid_amount' => 5000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-05-15', 'reference' => 'PR1', 'supplier_name' => 'S']);
        PurchaseReturnPayment::forceCreate(['purchase_return_id' => $purchaseReturn->id, 'amount' => 500000, 'date' => '2023-05-15', 'reference' => 'PAY-RET/1', 'payment_method' => 'Cash']);

        $expensePeriod = Expense::forceCreate(['setting_id' => $this->setting->id, 'status' => Expense::STATUS_APPROVED, 'amount' => 15000, 'date' => '2023-05-15', 'archived_at' => null, 'reference' => 'E2', 'category_id' => $category->id, 'details' => 'e']);

        // Outside dates (June)
        $saleOutside = Sale::forceCreate(['setting_id' => $this->setting->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 99900000, 'paid_amount' => 99900000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-06-15', 'reference' => 'S3', 'customer_name' => 'C']);
        SalePayment::forceCreate(['sale_id' => $saleOutside->id, 'amount' => 99900000, 'date' => '2023-06-15', 'reference' => 'SP3', 'payment_method' => 'Cash']);

        $service = new OperationalCashFlowReportService();
        $filter = new OperationalCashFlowReportFilterData('2023-05-01', '2023-05-31');
        $report = $service->generate($this->setting->id, $filter);

        // Opening cash: 100000 (sale) - 20000 (expense) = 80000
        $this->assertEquals(80000, $report->openingCash->amount);

        // Operating activities
        $operatingRows = collect($report->operatingActivities->rows);
        $this->assertEquals(150000, $operatingRows->where('name', 'Penerimaan dari pelanggan')->first()->amount);
        $this->assertEquals(-50000, $operatingRows->where('name', 'Pembayaran ke pemasok')->first()->amount);
        $this->assertEquals(-10000, $operatingRows->where('name', 'Kartu kredit dan liabilitas jangka pendek lainnya')->first()->amount);
        $this->assertEquals(5000, $operatingRows->where('name', 'Aset lancar lainnya')->first()->amount);
        $this->assertEquals(-15000, $operatingRows->where('name', 'Pengeluaran operasional')->first()->amount);
        
        $netOperating = 150000 - 50000 - 10000 + 5000 - 15000; // 80000
        $this->assertEquals(80000, $report->operatingActivities->total);

        // Placeholders
        $this->assertEquals(0, $report->investingActivities->total);
        $this->assertEquals(0, $report->financingActivities->total);
        
        $this->assertEquals(80000, $report->netCashIncrease->amount);
        $this->assertEquals(0, $report->bankRevaluation->amount);
        
        // Ending cash: 80000 (opening) + 80000 (net) = 160000
        $this->assertEquals(160000, $report->endingCash->amount);
        $this->assertEquals('IDR', $report->currencyCode);
    }

    public function test_livewire_component_renders_correctly()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Livewire::actingAs($user)
            ->test(OperationalCashFlowReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->call('generateReport')
            ->assertSee('Arus Kas (Operasional)')
            ->assertSee('Periode: 01 May 2023 - 31 May 2023')
            ->assertSee('(dalam IDR)')
            ->assertSee('Saldo kas awal')
            ->assertSee('Saldo kas akhir');
    }

    public function test_default_date_is_today()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Livewire::actingAs($user)
            ->test(OperationalCashFlowReport::class)
            ->assertSet('start_date', now()->format('Y-m-d'))
            ->assertSet('end_date', now()->format('Y-m-d'))
            ->assertSet('period_preset', 'today');
    }

    public function test_period_preset_updates_dates()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Livewire::actingAs($user)
            ->test(OperationalCashFlowReport::class)
            ->set('period_preset', 'this_week')
            ->assertSet('start_date', now()->startOfWeek()->format('Y-m-d'))
            ->assertSet('end_date', now()->endOfWeek()->format('Y-m-d'))
            ->set('period_preset', 'this_month')
            ->assertSet('start_date', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('end_date', now()->endOfMonth()->format('Y-m-d'))
            ->set('start_date', now()->subDay()->format('Y-m-d'))
            ->assertSet('period_preset', 'custom');
    }

    public function test_excel_export()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Excel::fake();

        Livewire::actingAs($user)
            ->test(OperationalCashFlowReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->call('exportExcel');

        Excel::assertDownloaded('arus_kas_01-05-2023_sd_31-05-2023.xlsx', function (OperationalCashFlowReportExport $export) {
            $array = $export->array();
            $contents = json_encode($array);

            $this->assertStringContainsString('Penerimaan dari pelanggan', $contents);
            $this->assertStringContainsString('Pembayaran ke pemasok', $contents);
            $this->assertStringContainsString('Arus Kas dari Aktivitas Investasi', $contents);
            $this->assertStringContainsString('Arus Kas dari Aktivitas Pendanaan', $contents);
            $this->assertStringContainsString('Saldo kas akhir', $contents);

            return true;
        });
    }

    public function test_csv_export()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Excel::fake();

        Livewire::actingAs($user)
            ->test(OperationalCashFlowReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->call('exportCsv');

        Excel::assertDownloaded('arus_kas_01-05-2023_sd_31-05-2023.csv', function (OperationalCashFlowReportExport $export) {
            $array = $export->array();
            
            // Check flat structure for CSV
            $this->assertEquals('Tipe Aktivitas', $array[0][0]);
            $this->assertEquals('Nama Label', $array[0][1]);
            
            $contents = json_encode($array);
            $this->assertStringContainsString('Penerimaan dari pelanggan', $contents);
            $this->assertStringContainsString('Kenaikan (penurunan) kas', $contents);
            $this->assertStringContainsString('Saldo kas akhir', $contents);

            return true;
        });
    }

    public function test_access_gate_denies_without_reports_access_permission()
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)->get(route('operational-cash-flow-report.index'));

        $this->assertEquals(403, $response->status());
    }

    public function test_access_gate_allows_with_reports_access_permission()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');
        session(['setting_id' => $this->setting->id]);

        $response = $this->actingAs($user)->get(route('operational-cash-flow-report.index'));

        $this->assertEquals(200, $response->status());
    }
}
