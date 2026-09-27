<?php

namespace Tests\Feature\Livewire\Reports;

use App\Livewire\Reports\OperationalGeneralLedgerReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use App\Models\User;
use Spatie\Permission\Models\Permission;

class OperationalGeneralLedgerReportTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'reports.access']);
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('reports.access');
        $this->actingAs($user);
        $this->setting = Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);
    }

    public function test_it_renders_successfully()
    {
        Livewire::test(OperationalGeneralLedgerReport::class)
            ->assertStatus(200)
            ->assertSee('Buku Besar');
    }

    public function test_it_displays_data_after_filter_applied()
    {
        Livewire::test(OperationalGeneralLedgerReport::class)
            ->set('start_date', now()->startOfMonth()->format('Y-m-d'))
            ->set('end_date', now()->endOfMonth()->format('Y-m-d'))
            ->call('generateReport')
            ->assertSee('Buku Besar');
    }

    public function test_it_exports_excel_successfully()
    {
        Livewire::test(OperationalGeneralLedgerReport::class)
            ->set('start_date', now()->startOfMonth()->format('Y-m-d'))
            ->set('end_date', now()->endOfMonth()->format('Y-m-d'))
            ->call('generateReport')
            ->call('exportExcel')
            ->assertFileDownloaded(sprintf('buku_besar_%s_sd_%s.xlsx', now()->startOfMonth()->format('d-m-Y'), now()->endOfMonth()->format('d-m-Y')));
    }

    public function test_aborts_without_permission()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // This route might not exist, but let's test component directly
        Livewire::test(OperationalGeneralLedgerReport::class)
            ->assertStatus(403);
    }

    public function test_it_can_expand_bucket_and_load_details()
    {
        $expenseCategory = \Modules\Expense\Entities\ExpenseCategory::create([
            'setting_id' => $this->setting->id,
            'category_name' => 'Test Expense Category'
        ]);

        \Modules\Expense\Entities\Expense::create([
            'setting_id' => $this->setting->id,
            'category_id' => $expenseCategory->id,
            'date' => now()->startOfMonth()->format('Y-m-d'),
            'reference' => 'EXP-001',
            'details' => 'Test expense',
            'amount' => 1000,
            'status' => 'Approved'
        ]);

        $component = Livewire::test(OperationalGeneralLedgerReport::class)
            ->set('start_date', now()->startOfMonth()->format('Y-m-d'))
            ->set('end_date', now()->endOfMonth()->format('Y-m-d'))
            ->call('generateReport')
            ->call('toggleBucket', 'cash_bank');
            
        $this->assertContains('cash_bank', $component->get('expandedBuckets'));
        $this->assertArrayHasKey('cash_bank', $component->get('loadedBucketDetails'));
    }

    public function test_it_clears_expanded_state_on_filter_change()
    {
        Livewire::test(OperationalGeneralLedgerReport::class)
            ->set('expandedBuckets', ['cash_bank'])
            ->set('loadedBucketDetails', ['cash_bank' => []])
            ->call('generateReport')
            ->assertSet('expandedBuckets', [])
            ->assertSet('loadedBucketDetails', []);
    }
}
