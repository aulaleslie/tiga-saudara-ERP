<?php

namespace Modules\Reports\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use App\Livewire\Reports\SalesTaxReport;
use Modules\Setting\Entities\Setting;
use Modules\Product\Entities\Category;
use Carbon\Carbon;
use App\Services\Reports\SalesTaxReportFilterData;
use App\Services\Reports\SalesTaxReportSnapshotService;

class SalesTaxReportLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);
        
        // Ensure testing against proper locale/auth if necessary
        $this->withoutExceptionHandling();
    }

    /** @test */
    public function it_mounts_with_default_dates()
    {
        Livewire::test(SalesTaxReport::class)
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'))
            ->assertSet('periodPreset', 'Bulan ini')
            ->assertSet('filterTriggered', false)
            ->assertSee('Silakan sesuaikan filter dan klik');
    }

    /** @test */
    public function it_applies_filters_and_updates_data()
    {
        Livewire::test(SalesTaxReport::class)
            ->set('startDate', Carbon::today()->format('Y-m-d'))
            ->set('endDate', Carbon::today()->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSet('filterTriggered', true)
            ->assertSee('Laporan Pajak Penjualan');
    }

    /** @test */
    public function it_blocks_export_when_filters_are_stale()
    {
        $component = Livewire::test(SalesTaxReport::class)
            ->set('startDate', Carbon::today()->format('Y-m-d'))
            ->set('endDate', Carbon::today()->format('Y-m-d'))
            ->call('applyFilters');

        // Now change a filter without applying
        $component->set('startDate', Carbon::yesterday()->format('Y-m-d'))
            ->call('exportExcel')
            ->assertDispatched('alert');
            
        $component->call('exportCsv')
            ->assertDispatched('alert');
    }

    /** @test */
    public function it_shows_empty_state_when_no_data()
    {
        Livewire::test(SalesTaxReport::class)
            ->set('startDate', Carbon::today()->addYears(10)->format('Y-m-d'))
            ->set('endDate', Carbon::today()->addYears(10)->format('Y-m-d'))
            ->call('applyFilters')
            ->assertSet('filterTriggered', true)
            ->assertSee('Tidak ada data yang sesuai dengan filter');
    }
}
