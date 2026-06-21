<?php

namespace Tests\Feature\Livewire\Reports;

use App\Exports\OperationalTrialBalanceReportCsvExport;
use App\Exports\OperationalTrialBalanceReportExport;
use App\Livewire\Reports\OperationalTrialBalanceReport;
use App\Services\Reports\OperationalTrialBalanceReportService;
use App\Services\Reports\OperationalTrialBalanceRowConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Expense\Entities\Expense;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class OperationalTrialBalanceReportTest extends TestCase
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

    public function test_operational_trial_balance_service_query_logic()
    {
        $category = \Modules\Expense\Entities\ExpenseCategory::forceCreate(['category_name' => 'Cat 1', 'category_description' => 'Test']);
        
        // Opening balance event (before 2023-05-01)
        // Sale revenue: normally credit
        Sale::forceCreate([
            'setting_id' => $this->setting->id, 
            'status' => Sale::STATUS_DISPATCHED, 
            'total_amount' => 100000, 
            'paid_amount' => 100000, 
            'due_amount' => 0, 
            'payment_status' => 'Paid', 
            'payment_method' => 'Cash', 
            'date' => '2023-04-15', 
            'reference' => 'S1', 
            'customer_name' => 'C'
        ]);
        
        // Period event (May 2023)
        Purchase::forceCreate([
            'setting_id' => $this->setting->id, 
            'status' => Purchase::STATUS_RECEIVED, 
            'total_amount' => 50000, 
            'paid_amount' => 50000, 
            'due_amount' => 0, 
            'payment_status' => 'Paid', 
            'payment_method' => 'Cash', 
            'date' => '2023-05-15', 
            'due_date' => '2023-05-15', 
            'reference' => 'P1', 
            'supplier_name' => 'S'
        ]);

        Expense::forceCreate([
            'setting_id' => $this->setting->id, 
            'status' => Expense::STATUS_APPROVED, 
            'amount' => 20000, 
            'date' => '2023-05-20', 
            'archived_at' => null, 
            'reference' => 'E1', 
            'category_id' => $category->id, 
            'details' => 'e'
        ]);

        // Outside dates (June)
        Sale::forceCreate([
            'setting_id' => $this->setting->id, 
            'status' => Sale::STATUS_DISPATCHED, 
            'total_amount' => 99900000, 
            'paid_amount' => 99900000, 
            'due_amount' => 0, 
            'payment_status' => 'Paid', 
            'payment_method' => 'Cash', 
            'date' => '2023-06-15', 
            'reference' => 'S2', 
            'customer_name' => 'C'
        ]);

        $service = app(OperationalTrialBalanceReportService::class);
        $report = $service->generate($this->setting->id, '2023-05-01', '2023-05-31');

        // Verify some properties
        $this->assertEquals('IDR', $report->currencyCode);
        $this->assertEquals('2023-05-01', $report->startDate);
        $this->assertEquals('2023-05-31', $report->endDate);

        // Find income category and check sales revenue row
        $incomeCategory = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_INCOME);
        $this->assertNotNull($incomeCategory);
        $salesRow = collect($incomeCategory->rows)->firstWhere('code', 'OP-400');
        $this->assertNotNull($salesRow);
        
        // Opening balance is a credit of 100000
        $this->assertEquals(0, $salesRow->openingDebit);
        $this->assertEquals(100000, $salesRow->openingCredit);

        // Find expense category and check expenses
        $expenseCategory = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_EXPENSE);
        $this->assertNotNull($expenseCategory);
        $expenseRow = collect($expenseCategory->rows)->firstWhere('code', 'OP-500');
        $this->assertNotNull($expenseRow);

        // Period debit of 70000 (50000 Purchase + 20000 Expense)
        $this->assertEquals(70000, $expenseRow->periodDebit);
        $this->assertEquals(0, $expenseRow->periodCredit);

        // Ending balance is a debit of 70000
        $this->assertEquals(70000, $expenseRow->endingDebit);
        $this->assertEquals(0, $expenseRow->endingCredit);
    }

    public function test_livewire_component_renders_correctly_and_shows_source_note()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Livewire::actingAs($user)
            ->test(OperationalTrialBalanceReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->call('generateReport')
            ->assertSee('Neraca Saldo')
            ->assertSee('Laporan ini dihitung dari nilai dokumen operasional') // Source note visibility
            ->assertSee('Periode 01 May 2023 - 31 May 2023')
            ->assertSee('(dalam IDR)');
    }

    public function test_default_dates_and_empty_state()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        $component = Livewire::actingAs($user)
            ->test(OperationalTrialBalanceReport::class);
        $component->assertSet('start_date', now()->format('Y-m-d'));
        $component->assertSet('end_date', now()->format('Y-m-d'));
        $component->assertSet('applied_start_date', now()->format('Y-m-d'));
        $component->assertSet('applied_end_date', now()->format('Y-m-d')); // Empty state

        $component->assertSee('Tidak ada transaksi yang sesuai dengan filter yang dipilih.');
    }

    public function test_applying_valid_filters_and_period_presets()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Livewire::actingAs($user)
            ->test(OperationalTrialBalanceReport::class)
            // Test preset changes dates
            ->set('period_preset', 'this_month')
            ->assertSet('start_date', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('end_date', now()->endOfMonth()->format('Y-m-d'))
            // Test applying invalid dates is rejected
            ->set('start_date', '2023-06-01')
            ->set('end_date', '2023-05-01')
            ->call('generateReport')
            ->assertHasErrors(['end_date' => 'after_or_equal'])
            // Test applying valid dates works
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->call('generateReport')
            ->assertHasNoErrors()
            ->assertSee('Periode 01 May 2023 - 31 May 2023');
    }

    public function test_excel_export_parity()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');
        
        Sale::forceCreate([
            'setting_id' => $this->setting->id, 
            'status' => Sale::STATUS_DISPATCHED, 
            'total_amount' => 100000, 
            'paid_amount' => 100000, 
            'due_amount' => 0, 
            'payment_status' => 'Paid', 
            'payment_method' => 'Cash', 
            'date' => '2023-05-15', 
            'reference' => 'S1', 
            'customer_name' => 'C'
        ]);

        Excel::fake();

        Livewire::actingAs($user)
            ->test(OperationalTrialBalanceReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->call('generateReport')
            ->call('exportExcel');

        Excel::assertDownloaded('neraca_saldo_01-05-2023_sd_31-05-2023.xlsx', function (\App\Exports\OperationalTrialBalanceReportExport $export) {
            $this->assertEquals('2023-05-01', $export->filters['startDate']);
            $this->assertEquals('2023-05-31', $export->filters['endDate']);

            $view = $export->view();
            $this->assertEquals('exports.operational-trial-balance-report', $view->name());
            
            $report = $view->getData()['report'];
            $incomeCategory = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_INCOME);
            $salesRow = collect($incomeCategory->rows)->firstWhere('code', 'OP-400');
            $this->assertEquals(100000, $salesRow->periodCredit);

            return true;
        });
    }

    public function test_csv_export_parity()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');
        
        Sale::forceCreate([
            'setting_id' => $this->setting->id, 
            'status' => Sale::STATUS_DISPATCHED, 
            'total_amount' => 100000, 
            'paid_amount' => 100000, 
            'due_amount' => 0, 
            'payment_status' => 'Paid', 
            'payment_method' => 'Cash', 
            'date' => '2023-05-15', 
            'reference' => 'S1', 
            'customer_name' => 'C'
        ]);

        Excel::fake();

        Livewire::actingAs($user)
            ->test(OperationalTrialBalanceReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->call('generateReport')
            ->call('exportCsv');

        Excel::assertDownloaded('neraca_saldo_01-05-2023_sd_31-05-2023.csv', function (OperationalTrialBalanceReportCsvExport $export) {
            $this->assertEquals('2023-05-01', $export->filters['startDate']);
            $this->assertEquals('2023-05-31', $export->filters['endDate']);

            $array = $export->array();
            $salesRow = collect($array)->firstWhere(1, 'OP-400');
            $this->assertNotNull($salesRow);
            $this->assertEquals(OperationalTrialBalanceRowConfig::CATEGORY_INCOME, $salesRow[0]);
            $this->assertEquals(100000, $salesRow[6]); // Pergerakan Kredit

            return true;
        });
    }

    public function test_access_gate_denies_without_reports_access_permission()
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)->get(route('operational-trial-balance-report.index'));

        $this->assertEquals(403, $response->status());
    }

    public function test_access_gate_allows_with_reports_access_permission()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');
        session(['setting_id' => $this->setting->id]);

        $response = $this->actingAs($user)->get(route('operational-trial-balance-report.index'));

        $this->assertEquals(200, $response->status());
    }
}
