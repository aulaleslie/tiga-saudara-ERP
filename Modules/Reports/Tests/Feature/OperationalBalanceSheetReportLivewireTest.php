<?php

namespace Modules\Reports\Tests\Feature;

use App\Livewire\Reports\OperationalBalanceSheetReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class OperationalBalanceSheetReportLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        $this->user = \App\Models\User::factory()->create(['setting_id' => $this->setting->id]);
        session(['setting_id' => $this->setting->id]);
    }

    public function test_authorization_prevents_access_without_permission()
    {
        $this->actingAs($this->user);
        
        // Remove permissions
        $this->user->roles()->detach();

        Livewire::test(OperationalBalanceSheetReport::class)
            ->assertForbidden();
            
        $this->get(route('operational-balance-sheet-report.index'))
            ->assertForbidden();
    }

    public function test_authorized_user_can_render_report_and_has_default_date()
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('reports.access');

        Livewire::test(OperationalBalanceSheetReport::class)
            ->assertOk()
            ->assertSet('as_of_date', now()->format('Y-m-d'))
            ->assertSee('Neraca (Operasional)')
            ->assertSee('Total Aset')
            ->assertSee('Total Liabilitas dan Modal');
            
        $this->get(route('operational-balance-sheet-report.index'))
            ->assertOk()
            ->assertSeeLivewire('reports.operational-balance-sheet-report');
    }

    public function test_custom_as_of_date_filtering()
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('reports.access');

        $pastDate = now()->subDays(5)->format('Y-m-d');

        Livewire::test(OperationalBalanceSheetReport::class)
            ->set('as_of_date', $pastDate)
            ->call('generateReport')
            ->assertOk()
            ->assertSee('Per ' . Carbon::parse($pastDate)->format('d M Y'));
    }

    public function test_source_note_visibility_and_no_account_column()
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('reports.access');

        Livewire::test(OperationalBalanceSheetReport::class)
            ->assertSee('Laporan ini dihitung dari nilai dokumen operasional')
            ->assertDontSee('Nomor Akun'); // Should not have account number column
    }

    public function test_export_uses_filter_and_returns_download()
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('reports.access');

        $testDate = now()->subDays(2)->format('Y-m-d');

        Livewire::test(OperationalBalanceSheetReport::class)
            ->set('as_of_date', $testDate)
            ->call('exportExcel')
            ->assertFileDownloaded('neraca_' . Carbon::parse($testDate)->format('d-m-Y') . '.xlsx');
    }
}
