<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;

class PurchaseReportExportParityTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $role = \Spatie\Permission\Models\Role::create(['name' => 'super_admin']);
        $permission = \Spatie\Permission\Models\Permission::create(['name' => 'access_reports']);
        $role->givePermissionTo($permission);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1
        ]);

        $this->setting = \Modules\Setting\Entities\Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole($role);
    }

    /** @test */
    public function it_blocks_export_before_running_report()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->call('exportExcel')
            ->assertDispatched('alert');
    }

    /** @test */
    public function it_invalidates_export_when_filters_change()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters') // Create snapshot
            ->set('startDate', '2020-01-01') // Change filter
            ->call('exportExcel')
            ->assertDispatched('alert');
    }

    /** @test */
    public function it_allows_export_after_successful_report_run()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters')
            ->call('exportExcel')
            ->assertNotDispatched('alert');
    }

    /** @test */
    public function it_exports_with_period_metadata()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $startDate = '2025-01-15';
        $endDate = '2025-01-31';

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->set('startDate', $startDate)
            ->set('endDate', $endDate)
            ->call('applyFilters')
            ->assertViewHas('purchases');

        // Verify export can be created with same filter parameters
        $export = new \App\Exports\PurchaseReportExport([
            'startDate' => $startDate,
            'endDate' => $endDate,
            'supplierId' => null,
            'withTax' => null,
            'selectedTag' => null,
            'status' => null,
            'paymentStatus' => null,
            'isGlobal' => false,
            'scopeSettingId' => $this->setting->id,
        ]);

        $collection = $export->collection();
        $this->assertIsIterable($collection, 'Export collection should be iterable');
    }

    /** @test */
    public function it_blocks_export_after_filter_modification_during_csv_export()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters')
            ->set('supplierId', 999) // Change filter
            ->call('exportCsv')
            ->assertDispatched('alert');
    }

    /** @test */
    public function it_blocks_export_after_filter_modification_during_pdf_export()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters')
            ->set('paymentStatus', 'PAID') // Change filter
            ->call('exportPdf')
            ->assertDispatched('alert');
    }
}
