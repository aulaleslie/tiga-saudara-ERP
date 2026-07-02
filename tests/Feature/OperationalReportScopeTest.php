<?php

namespace Tests\Feature;

use App\Exports\OperationalBalanceSheetReportExport;
use App\Livewire\Reports\OperationalBalanceSheetReport;
use App\Services\Reports\OperationalBalanceSheetReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Expense\Entities\Expense;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class OperationalReportScopeTest extends TestCase
{
    use RefreshDatabase;

    protected $setting1;
    protected $setting2;

    protected function setUp(): void
    {
        parent::setUp();

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'reports.access']);

        $currency = Currency::factory()->create(['code' => 'IDR']);
        
        $this->setting1 = Setting::factory()->create(['company_name' => 'Business A', 'default_currency_id' => $currency->id]);
        $this->setting2 = Setting::factory()->create(['company_name' => 'Business B', 'default_currency_id' => $currency->id]);
        
        session(['setting_id' => $this->setting1->id]);
    }

    public function test_report_defaults_to_current_session_setting_id()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Livewire::actingAs($user)
            ->test(OperationalBalanceSheetReport::class)
            ->assertSet('selectedSettingIds', [])
            ->assertViewHas('scopeLabel', strtoupper('Business A'));
    }

    public function test_cross_business_service_includes_selected_and_excludes_unselected()
    {
        // Business A has a sale of 1000
        $saleA = Sale::forceCreate(['setting_id' => $this->setting1->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 1000, 'paid_amount' => 1000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => now()->format('Y-m-d'), 'reference' => 'SA', 'customer_name' => 'C']);
        SalePayment::forceCreate(['sale_id' => $saleA->id, 'amount' => 1000, 'date' => now()->format('Y-m-d'), 'reference' => 'SPA', 'payment_method' => 'Cash', 'status' => 'ACTIVE']);
        
        // Business B has a sale of 2000
        $saleB = Sale::forceCreate(['setting_id' => $this->setting2->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 2000, 'paid_amount' => 2000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => now()->format('Y-m-d'), 'reference' => 'SB', 'customer_name' => 'C']);
        SalePayment::forceCreate(['sale_id' => $saleB->id, 'amount' => 2000, 'date' => now()->format('Y-m-d'), 'reference' => 'SPB', 'payment_method' => 'Cash', 'status' => 'ACTIVE']);

        $service = new OperationalBalanceSheetReportService();
        
        // 1. Only Business A
        $reportA = $service->generate([$this->setting1->id], now()->format('Y-m-d'));
        $this->assertEquals(1000, $reportA->assets->rows[0]->amount); // Kas & Bank

        // 2. Only Business B
        $reportB = $service->generate([$this->setting2->id], now()->format('Y-m-d'));
        $this->assertEquals(2000, $reportB->assets->rows[0]->amount);

        // 3. Both Businesses
        $reportBoth = $service->generate([$this->setting1->id, $this->setting2->id], now()->format('Y-m-d'));
        $this->assertEquals(3000, $reportBoth->assets->rows[0]->amount);
    }
    
    public function test_export_parity_uses_same_selected_settings()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Excel::fake();

        Livewire::actingAs($user)
            ->test(OperationalBalanceSheetReport::class)
            ->set('selectedSettingIds', [$this->setting1->id, $this->setting2->id])
            ->call('exportExcel');

        Excel::assertDownloaded(sprintf('neraca_%s.xlsx', now()->format('d-m-Y')), function (OperationalBalanceSheetReportExport $export) {
            $array = $export->array();
            $contents = json_encode($array);

            // The multi-source label should be in the header
            $this->assertStringContainsString('Semua Perusahaan', $contents);

            return true;
        });
    }

    public function test_general_ledger_service_cross_business()
    {
        $saleA = Sale::forceCreate(['setting_id' => $this->setting1->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 1000, 'paid_amount' => 1000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => now()->format('Y-m-d'), 'reference' => 'SA_GL', 'customer_name' => 'C']);
        SalePayment::forceCreate(['sale_id' => $saleA->id, 'amount' => 1000, 'date' => now()->format('Y-m-d'), 'reference' => 'SPA_GL', 'payment_method' => 'Cash', 'status' => 'ACTIVE']);
        
        $saleB = Sale::forceCreate(['setting_id' => $this->setting2->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 2000, 'paid_amount' => 2000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => now()->format('Y-m-d'), 'reference' => 'SB_GL', 'customer_name' => 'C']);
        SalePayment::forceCreate(['sale_id' => $saleB->id, 'amount' => 2000, 'date' => now()->format('Y-m-d'), 'reference' => 'SPB_GL', 'payment_method' => 'Cash', 'status' => 'ACTIVE']);

        $service = app(\App\Services\Reports\OperationalGeneralLedgerReportService::class);
        $filter = new \App\Services\Reports\OperationalGeneralLedgerReportFilterData(now()->format('Y-m-d'), now()->format('Y-m-d'), [\App\Services\Reports\OperationalGeneralLedgerBucketConfig::CASH_BANK]);
        
        $reportA = $service->generate([$this->setting1->id], $filter);
        $this->assertEquals(1000, $reportA->buckets[0]->endingBalance);

        $reportB = $service->generate([$this->setting2->id], $filter);
        $this->assertEquals(2000, $reportB->buckets[0]->endingBalance);

        $reportBoth = $service->generate([$this->setting1->id, $this->setting2->id], $filter);
        $this->assertEquals(3000, $reportBoth->buckets[0]->endingBalance);
    }

    public function test_trial_balance_service_cross_business()
    {
        $saleA = Sale::forceCreate(['setting_id' => $this->setting1->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 1000, 'paid_amount' => 1000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => now()->format('Y-m-d'), 'reference' => 'SA_TB', 'customer_name' => 'C']);
        SalePayment::forceCreate(['sale_id' => $saleA->id, 'amount' => 1000, 'date' => now()->format('Y-m-d'), 'reference' => 'SPA_TB', 'payment_method' => 'Cash', 'status' => 'ACTIVE']);
        
        $saleB = Sale::forceCreate(['setting_id' => $this->setting2->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 2000, 'paid_amount' => 2000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => now()->format('Y-m-d'), 'reference' => 'SB_TB', 'customer_name' => 'C']);
        SalePayment::forceCreate(['sale_id' => $saleB->id, 'amount' => 2000, 'date' => now()->format('Y-m-d'), 'reference' => 'SPB_TB', 'payment_method' => 'Cash', 'status' => 'ACTIVE']);

        $service = app(\App\Services\Reports\OperationalTrialBalanceReportService::class);
        
        $reportA = $service->generate([$this->setting1->id], now()->format('Y-m-d'), now()->format('Y-m-d'));
        $this->assertEquals(1000, collect($reportA->categories)->firstWhere('categoryName', 'Aset')->rows[0]->endingDebit);

        $reportB = $service->generate([$this->setting2->id], now()->format('Y-m-d'), now()->format('Y-m-d'));
        $this->assertEquals(2000, collect($reportB->categories)->firstWhere('categoryName', 'Aset')->rows[0]->endingDebit);

        $reportBoth = $service->generate([$this->setting1->id, $this->setting2->id], now()->format('Y-m-d'), now()->format('Y-m-d'));
        $this->assertEquals(3000, collect($reportBoth->categories)->firstWhere('categoryName', 'Aset')->rows[0]->endingDebit);
    }

    public function test_cash_flow_service_cross_business()
    {
        $saleA = Sale::forceCreate(['setting_id' => $this->setting1->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 1000, 'paid_amount' => 1000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => now()->format('Y-m-d'), 'reference' => 'SA_CF', 'customer_name' => 'C']);
        SalePayment::forceCreate(['sale_id' => $saleA->id, 'amount' => 1000, 'date' => now()->format('Y-m-d'), 'reference' => 'SPA_CF', 'payment_method' => 'Cash', 'status' => 'ACTIVE']);
        
        $saleB = Sale::forceCreate(['setting_id' => $this->setting2->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => 2000, 'paid_amount' => 2000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => now()->format('Y-m-d'), 'reference' => 'SB_CF', 'customer_name' => 'C']);
        SalePayment::forceCreate(['sale_id' => $saleB->id, 'amount' => 2000, 'date' => now()->format('Y-m-d'), 'reference' => 'SPB_CF', 'payment_method' => 'Cash', 'status' => 'ACTIVE']);

        $service = app(\App\Services\Reports\OperationalCashFlowReportService::class);
        $filter = new \App\Services\Reports\OperationalCashFlowReportFilterData(now()->format('Y-m-d'), now()->format('Y-m-d'));
        
        $reportA = $service->generate([$this->setting1->id], $filter);
        $this->assertEquals(1000, $reportA->operatingActivities->rows[0]->amount); // Penerimaan dari Pelanggan

        $reportB = $service->generate([$this->setting2->id], $filter);
        $this->assertEquals(2000, $reportB->operatingActivities->rows[0]->amount);

        $reportBoth = $service->generate([$this->setting1->id, $this->setting2->id], $filter);
        $this->assertEquals(3000, $reportBoth->operatingActivities->rows[0]->amount);
    }

    public function test_general_ledger_export_parity()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Excel::fake();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Reports\OperationalGeneralLedgerReport::class)
            ->set('selectedSettingIds', [$this->setting1->id, $this->setting2->id])
            ->call('exportExcel');

        Excel::assertDownloaded(sprintf('buku_besar_%s_sd_%s.xlsx', now()->format('d-m-Y'), now()->format('d-m-Y')), function (\App\Exports\OperationalGeneralLedgerReportExport $export) {
            $view = $export->view();
            $this->assertStringContainsString('Semua Perusahaan', $view->getData()['scopeLabel']);
            return true;
        });
    }

    public function test_trial_balance_export_parity()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Excel::fake();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Reports\OperationalTrialBalanceReport::class)
            ->set('selectedSettingIds', [$this->setting1->id, $this->setting2->id])
            ->call('exportExcel');

        Excel::assertDownloaded(sprintf('neraca_saldo_%s_sd_%s.xlsx', now()->format('d-m-Y'), now()->format('d-m-Y')), function (\App\Exports\OperationalTrialBalanceReportExport $export) {
            $view = $export->view();
            $this->assertStringContainsString('Semua Perusahaan', $view->getData()['scopeLabel']);
            return true;
        });
    }

    public function test_cash_flow_export_parity()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        Excel::fake();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Reports\OperationalCashFlowReport::class)
            ->set('selectedSettingIds', [$this->setting1->id, $this->setting2->id])
            ->call('exportExcel');

        Excel::assertDownloaded(sprintf('arus_kas_%s_sd_%s.xlsx', now()->format('d-m-Y'), now()->format('d-m-Y')), function (\App\Exports\OperationalCashFlowReportExport $export) {
            $array = $export->array();
            $contents = json_encode($array);
            $this->assertStringContainsString('Semua Perusahaan', $contents);
            return true;
        });
    }
}
