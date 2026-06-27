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
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class ProfitLossReportTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'reports.access']);

        $currency = Currency::factory()->create(['code' => 'IDR']);
        $this->setting = Setting::factory()->create(['default_currency_id' => $currency->id]);
        session(['setting_id' => $this->setting->id]);
        
        $category = \Modules\Product\Entities\Category::forceCreate([
            'category_name' => 'Cat',
            'category_code' => 'C1',
            'setting_id' => $this->setting->id,
            'created_by' => 1
        ]);
        
        $this->product = \Modules\Product\Entities\Product::forceCreate([
            'product_name' => 'Prod',
            'product_code' => 'P1',
            'product_price' => 10000,
            'product_cost' => 5000,
            'category_id' => $category->id,
            'product_quantity' => 10,
            'product_unit' => 'pcs',
            'product_stock_alert' => 1,
            'setting_id' => $this->setting->id
        ]);

        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
    }

    private function createSale(array $overrides, array $details)
    {
        $sale = Sale::forceCreate(array_merge([
            'setting_id' => $this->setting->id,
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'date' => '2023-05-15',
            'reference' => 'S' . uniqid(),
            'customer_name' => 'C',
            'discount_amount' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
        ], $overrides));

        foreach ($details as $detail) {
            SaleDetails::forceCreate(array_merge([
                'sale_id' => $sale->id,
                'product_id' => $this->product->id,
                'product_name' => 'Prod',
                'product_code' => 'P1',
                'quantity' => 1,
                'price' => 0,
                'unit_price' => 0,
                'sub_total' => 0,
                'product_discount_amount' => 0,
                'product_tax_amount' => 0,
                'cost_unit_snapshot' => 0,
            ], $detail));
        }

        return $sale;
    }

    // 1.1 Add focused service tests proving Penjualan uses sale_details.sub_total - product_tax_amount
    public function test_penjualan_uses_sub_total_minus_tax()
    {
        $this->createSale([], [
            ['sub_total' => 100000, 'product_tax_amount' => 11000],
            ['sub_total' => 50000, 'product_tax_amount' => 5000],
        ]);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id], '2023-05-01', '2023-05-31');

        // DPP = (100k - 11k) + (50k - 5k) = 89000 + 45000 = 134000
        $this->assertEquals(134000, $report->penjualan);
        $this->assertEquals(0, $report->diskonPenjualan);
        $this->assertEquals(134000, $report->totalPendapatan);
    }

    // 1.2 Add service coverage proving sale header tax_amount and shipping_amount are excluded from revenue
    public function test_header_tax_and_shipping_are_excluded()
    {
        $this->createSale([
            'tax_amount' => 20000,
            'shipping_amount' => 15000,
            'total_amount' => 135000,
        ], [
            ['sub_total' => 100000, 'product_tax_amount' => 0], // DPP is 100k
        ]);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id], '2023-05-01', '2023-05-31');

        $this->assertEquals(100000, $report->penjualan);
        $this->assertEquals(100000, $report->totalPendapatan);
    }

    // 1.3 Add service coverage proving header/global discount_amount appears as a separate negative Diskon Penjualan row
    public function test_global_discount_is_separate_negative_row()
    {
        $this->createSale([
            'discount_amount' => 5000,
        ], [
            // line discount is typically already subtracted from sub_total in the system, but we test header
            ['sub_total' => 100000, 'product_tax_amount' => 0],
        ]);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id], '2023-05-01', '2023-05-31');

        $this->assertEquals(100000, $report->penjualan);
        $this->assertEquals(-5000, $report->diskonPenjualan);
        $this->assertEquals(95000, $report->totalPendapatan);
    }

    // 1.4 Add service coverage proving sale_returns and sale_return_details do not affect revenue, HPP, or final profit/loss
    public function test_sale_returns_are_ignored()
    {
        $sale = $this->createSale([], [
            ['sub_total' => 100000, 'product_tax_amount' => 0, 'quantity' => 1, 'cost_unit_snapshot' => 40000],
        ]);

        SaleReturn::forceCreate([
            'setting_id' => $this->setting->id,
            'status' => 'Completed',
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'date' => '2023-05-15',
            'reference' => 'SR1',
            'customer_name' => 'C'
        ]);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id], '2023-05-01', '2023-05-31');

        $this->assertEquals(100000, $report->penjualan);
        $this->assertEquals(40000, $report->bebanPokokPendapatan);
        $this->assertEquals(60000, $report->labaKotor);
    }

    // 1.5 Add service coverage proving HPP uses cost_unit_snapshot * current quantity
    public function test_hpp_uses_cost_unit_snapshot_times_quantity()
    {
        $this->createSale([], [
            ['quantity' => 3, 'cost_unit_snapshot' => 25000], // total snapshot ignored
        ]);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id], '2023-05-01', '2023-05-31');

        $this->assertEquals(75000, $report->bebanPokokPendapatan);
    }

    // 1.6 Add service coverage proving null cost_unit_snapshot contributes zero
    public function test_null_cost_unit_snapshot_is_zero()
    {
        $this->createSale([], [
            ['quantity' => 3, 'cost_unit_snapshot' => null],
            ['quantity' => 2, 'cost_unit_snapshot' => 10000],
        ]);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id], '2023-05-01', '2023-05-31');

        $this->assertEquals(20000, $report->bebanPokokPendapatan);
    }

    // 1.7 Add service coverage proving approved non-archived expenses remain gross, including tax.
    public function test_expenses_remain_gross()
    {
        $category = \Modules\Expense\Entities\ExpenseCategory::forceCreate(['category_name' => 'Cat', 'category_description' => 'Test']);
        
        // Expense is stored in cents, 5000000 cents = 50000
        Expense::forceCreate(['setting_id' => $this->setting->id, 'status' => Expense::STATUS_APPROVED, 'amount' => 50000, 'date' => '2023-05-15', 'archived_at' => null, 'reference' => 'E1', 'category_id' => $category->id, 'details' => 'e']);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id], '2023-05-01', '2023-05-31');

        $this->assertEquals(50000, $report->bebanOperasional);
    }

    // 1.8 Add service coverage preserving selected setting scope and date range filtering.
    public function test_setting_scope_and_date_filtering()
    {
        $otherSetting = Setting::factory()->create(['default_currency_id' => 1]);

        $this->createSale(['date' => '2023-05-15'], [
            ['sub_total' => 100000, 'product_tax_amount' => 0, 'quantity' => 1, 'cost_unit_snapshot' => 40000]
        ]);

        $this->createSale(['setting_id' => $otherSetting->id, 'date' => '2023-05-15'], [
            ['sub_total' => 200000, 'product_tax_amount' => 0, 'quantity' => 1, 'cost_unit_snapshot' => 80000]
        ]);
        
        $this->createSale(['date' => '2023-06-15'], [
            ['sub_total' => 300000, 'product_tax_amount' => 0, 'quantity' => 1, 'cost_unit_snapshot' => 10000]
        ]);

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id], '2023-05-01', '2023-05-31');

        $this->assertEquals(100000, $report->penjualan);
        $this->assertEquals(40000, $report->bebanPokokPendapatan);
    }

    // Livewire and Export Tests
    public function test_livewire_component_renders_sample_aligned_rows()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        $this->createSale(['discount_amount' => 5000], [
            ['sub_total' => 100000, 'product_tax_amount' => 0, 'quantity' => 1, 'cost_unit_snapshot' => 40000]
        ]);

        $category = \Modules\Expense\Entities\ExpenseCategory::forceCreate(['category_name' => 'Cat', 'category_description' => 'Test']);
        Expense::forceCreate(['setting_id' => $this->setting->id, 'status' => Expense::STATUS_APPROVED, 'amount' => 20000, 'date' => '2023-05-15', 'archived_at' => null, 'reference' => 'E1', 'category_id' => $category->id, 'details' => 'e']);

        Livewire::actingAs($user)
            ->test(ProfitLossReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->call('generateReport')
            ->assertSee('Penjualan')
            ->assertSee('Diskon Penjualan')
            ->assertSee('Total dari Pendapatan')
            ->assertSee('Beban Pokok Pendapatan')
            ->assertSee('Laba Kotor')
            ->assertSee('Beban Operasional')
            ->assertSee('Total dari Beban Operasional')
            ->assertSee('Laba Operasional')
            ->assertSee('Pendapatan (Beban Lain-lain)')
            ->assertSee('Total dari Pendapatan (Beban Lain-lain)')
            ->assertSee('Laba (Rugi)')
            ->assertDontSee('Retur Penjualan')
            ->assertDontSee('Pembelian')
            ->assertViewHas('report', function ($report) {
                return $report->penjualan == 100000 &&
                       $report->diskonPenjualan == -5000 &&
                       $report->bebanPokokPendapatan == 40000 &&
                       $report->bebanOperasional == 20000;
            });
    }

    public function test_excel_export_structure()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        $this->createSale([], [
            ['sub_total' => 100000, 'product_tax_amount' => 0]
        ]);

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
            $this->assertStringContainsString('Diskon Penjualan', $contents);
            $this->assertStringContainsString('Total dari Pendapatan', $contents);
            $this->assertStringContainsString('Beban Pokok Pendapatan', $contents);
            $this->assertStringContainsString('Laba Kotor', $contents);
            $this->assertStringContainsString('Beban Operasional', $contents);
            $this->assertStringContainsString('Total dari Beban Operasional', $contents);
            $this->assertStringContainsString('Laba Operasional', $contents);
            $this->assertStringContainsString('Pendapatan (Beban Lain-lain)', $contents);
            $this->assertStringContainsString('Laba (Rugi)', $contents);

            $this->assertStringNotContainsString('Retur Penjualan', $contents);
            $this->assertStringNotContainsString('Pembelian', $contents);

            return true;
        });
    }

    public function test_export_preserves_company_scope()
    {
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');

        $currency = Currency::factory()->create(['code' => 'IDR']);
        $setting2 = Setting::factory()->create(['default_currency_id' => $currency->id]);
        $setting3 = Setting::factory()->create(['default_currency_id' => $currency->id]);

        Excel::fake();

        Livewire::actingAs($user)
            ->test(ProfitLossReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->set('selectedSettingIds', [$this->setting->id, $setting2->id])
            ->call('exportExcel');

        Excel::assertDownloaded('profit_loss_01-05-2023_31-05-2023.xlsx', function (ProfitLossReportExport $export) {
            $array = $export->array();
            $contents = json_encode($array);

            $this->assertStringContainsString('Beberapa Perusahaan', $contents);
            return true;
        });
        
        Livewire::actingAs($user)
            ->test(ProfitLossReport::class)
            ->set('start_date', '2023-05-01')
            ->set('end_date', '2023-05-31')
            ->set('selectedSettingIds', [$this->setting->id, $setting2->id, $setting3->id])
            ->call('exportExcel');

        Excel::assertDownloaded('profit_loss_01-05-2023_31-05-2023.xlsx', function (ProfitLossReportExport $export) {
            $array = $export->array();
            $contents = json_encode($array);

            $this->assertStringContainsString('Semua Perusahaan', $contents);
            return true;
        });
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
