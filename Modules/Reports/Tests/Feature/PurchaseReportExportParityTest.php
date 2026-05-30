<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
    public function it_blocks_excel_export_with_disabled_message()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters')
            ->call('exportExcel')
            ->assertDispatched('alert', function ($eventName, $eventData) {
                return $eventName === 'alert'
                    && isset($eventData[0]['message'])
                    && str_contains($eventData[0]['message'], 'belum tersedia');
            });
    }

    /** @test */
    public function it_blocks_csv_export_with_disabled_message()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters')
            ->call('exportCsv')
            ->assertDispatched('alert', function ($eventName, $eventData) {
                return $eventName === 'alert'
                    && isset($eventData[0]['message'])
                    && str_contains($eventData[0]['message'], 'belum tersedia');
            });
    }

    /** @test */
    public function it_blocks_pdf_export_with_disabled_message()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $this->setting->id)
            ->call('applyFilters')
            ->call('exportPdf')
            ->assertDispatched('alert', function ($eventName, $eventData) {
                return $eventName === 'alert'
                    && isset($eventData[0]['message'])
                    && str_contains($eventData[0]['message'], 'belum tersedia');
            });
    }

    /** @test */
    public function it_initializes_with_current_month_dates()
    {
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        \Livewire\Livewire::actingAs($this->user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'));
    }
}
