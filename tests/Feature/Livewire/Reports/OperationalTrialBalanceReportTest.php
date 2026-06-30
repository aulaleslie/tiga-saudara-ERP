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
use Modules\Sale\Entities\SaleDetails;
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

    private function createProduct()
    {
        $category = \Modules\Product\Entities\Category::create([
            'category_code' => uniqid(),
            'category_name' => 'Category ' . uniqid(),
            'created_by' => 1,
            'setting_id' => $this->setting->id,
        ]);

        return \Modules\Product\Entities\Product::create([
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
            'product_name' => 'Test',
            'product_code' => uniqid(),
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 100,
            'product_cost' => 600,
            'product_price' => 1000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 10,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => '',
        ]);
    }

    public function test_operational_trial_balance_service_query_logic()
    {
        $category = \Modules\Expense\Entities\ExpenseCategory::forceCreate(['category_name' => 'Cat 1', 'category_description' => 'Test']);
        $product = $this->createProduct();
        
        $sale1 = Sale::forceCreate([
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
        
        // Add SaleDetail for revenue to be generated
        SaleDetails::forceCreate([
            'sale_id' => $sale1->id,
            'product_id' => $product->id,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'price' => 100000,
            'unit_price' => 100000,
            'quantity' => 1,
            'sub_total' => 100000,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
            'cost_unit_snapshot' => 60000, // This will generate HPP
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
        $product2 = $this->createProduct();

        $sale2 = Sale::forceCreate([
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
        
        SaleDetails::forceCreate([
            'sale_id' => $sale2->id,
            'product_id' => $product2->id,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'price' => 99900000,
            'unit_price' => 99900000,
            'quantity' => 1,
            'sub_total' => 99900000,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
            'cost_unit_snapshot' => 60000,
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

        // Period debit of 20000 Expense, plus 60000 HPP from Sale S1?
        // Wait, S1 was in April, so its HPP is in opening balance.
        // There are no sales in May, so OP-500 is just 20000 Expense.
        $this->assertEquals(20000, $expenseRow->periodDebit);
        $this->assertEquals(0, $expenseRow->periodCredit);

        // Opening balance for OP-500 has S1's HPP (60000)
        // Ending balance is 60000 + 20000 = 80000
        $this->assertEquals(80000, $expenseRow->endingDebit);
        $this->assertEquals(0, $expenseRow->endingCredit);

        // Check Inventory bucket (OP-120)
        $assetCategory = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_ASSET);
        $inventoryRow = collect($assetCategory->rows)->firstWhere('code', 'OP-120');
        $this->assertNotNull($inventoryRow);

        // S1 in April reduced inventory by 60000
        $this->assertEquals(0, $inventoryRow->openingDebit);
        $this->assertEquals(60000, $inventoryRow->openingCredit);

        // May purchase increased inventory by 50000
        $this->assertEquals(50000, $inventoryRow->periodDebit);
        $this->assertEquals(0, $inventoryRow->periodCredit);

        // Ending inventory is -10000 (credit)
        $this->assertEquals(0, $inventoryRow->endingDebit);
        $this->assertEquals(10000, $inventoryRow->endingCredit);
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
        
        $product3 = $this->createProduct();
        
        $sale3 = Sale::forceCreate([
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

        SaleDetails::forceCreate([
            'sale_id' => $sale3->id,
            'product_id' => $product3->id,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'price' => 100000,
            'unit_price' => 100000,
            'quantity' => 1,
            'sub_total' => 100000,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
            'cost_unit_snapshot' => 60000,
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
        
        $product4 = $this->createProduct();

        $sale4 = Sale::forceCreate([
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

        SaleDetails::forceCreate([
            'sale_id' => $sale4->id,
            'product_id' => $product4->id,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'price' => 100000,
            'unit_price' => 100000,
            'quantity' => 1,
            'sub_total' => 100000,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
            'cost_unit_snapshot' => 60000,
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
