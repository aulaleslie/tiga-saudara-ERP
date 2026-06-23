<?php

namespace Modules\Reports\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Livewire\Reports\SalesTaxReport;
use Modules\Setting\Entities\Setting;
use Carbon\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SalesTaxReportExport;

class SalesTaxReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);
        $this->withoutExceptionHandling();
    }

    /** @test */
    public function it_exports_csv_without_metadata_and_subtotals()
    {
        Excel::fake();

        Livewire::test(SalesTaxReport::class)
            ->set('startDate', Carbon::today()->format('Y-m-d'))
            ->set('endDate', Carbon::today()->format('Y-m-d'))
            ->call('applyFilters')
            ->call('exportCsv');

        Excel::assertDownloaded('sales_tax_report_' . Carbon::today()->format('Y-m-d') . '_' . Carbon::today()->format('Y-m-d') . '.csv', function (SalesTaxReportExport $export) {
            $headings = $export->headings();
            $this->assertContains('Nama Pajak', $headings);
            $this->assertContains('Rate Pajak', $headings);
            
            // Should start at A1
            $this->assertEquals('A1', $export->startCell());
            return true;
        });
    }

    /** @test */
    public function it_exports_xlsx_with_metadata_and_subtotals()
    {
        Excel::fake();

        Livewire::test(SalesTaxReport::class)
            ->set('startDate', Carbon::today()->format('Y-m-d'))
            ->set('endDate', Carbon::today()->format('Y-m-d'))
            ->call('applyFilters')
            ->call('exportExcel');

        Excel::assertDownloaded('SalesTaxReport_' . Carbon::today()->format('Y-m-d') . '_' . Carbon::today()->format('Y-m-d') . '.xlsx', function (SalesTaxReportExport $export) {
            $headings = $export->headings();
            $this->assertContains('Tanggal', $headings);
            $this->assertContains('Rate Pajak', $headings);
            
            // Should start at A6 due to metadata
            $this->assertEquals('A6', $export->startCell());
            return true;
        });
    }

    /** @test */
    public function it_rejects_export_if_filter_drifts_without_applying()
    {
        Excel::fake();

        $component = Livewire::test(SalesTaxReport::class)
            ->set('startDate', Carbon::today()->format('Y-m-d'))
            ->set('endDate', Carbon::today()->format('Y-m-d'))
            ->call('applyFilters');

        // Drift filter
        $component->set('startDate', Carbon::yesterday()->format('Y-m-d'))
            ->call('exportExcel')
            ->assertDispatched('alert');
    }
}
