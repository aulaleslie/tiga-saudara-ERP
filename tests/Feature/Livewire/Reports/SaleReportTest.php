<?php

namespace Tests\Feature\Livewire\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Livewire\Livewire;
use App\Livewire\Reports\SaleReport;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class SaleReportTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'sales.access']);
        Permission::firstOrCreate(['name' => 'sales.show']);
        Role::firstOrCreate(['name' => 'Staff']);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1
        ]);

        $this->setting = Setting::create([
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
        $this->user->assignRole('Staff');
        $this->user->givePermissionTo('sales.access');
        $this->user->givePermissionTo('sales.show');

        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
        
        session(['setting_id' => $this->setting->id]);
    }

    public function test_selecting_period_preset_updates_rendered_date_input_values()
    {
        $component = Livewire::actingAs($this->user)
            ->test(SaleReport::class);

        // Select "today"
        $component->set('periodPreset', 'today');

        $today = now()->format('Y-m-d');
        
        $component->assertSet('startDate', $today)
            ->assertSet('endDate', $today)
            ->assertSeeHtml('value="' . $today . '"'); // Checks that input values are updated
            
        // Select "this_year"
        $component->set('periodPreset', 'this_year');
        $startOfYear = now()->startOfYear()->format('Y-m-d');
        $endOfYear = now()->endOfYear()->format('Y-m-d');
        
        $component->assertSet('startDate', $startOfYear)
            ->assertSet('endDate', $endOfYear)
            ->assertSeeHtml('value="' . $startOfYear . '"')
            ->assertSeeHtml('value="' . $endOfYear . '"');
    }

    public function test_report_rows_are_still_based_on_appliedFilters_until_filter_is_clicked()
    {
        $component = Livewire::actingAs($this->user)
            ->test(SaleReport::class);
            
        // Initial state
        $appliedFilters = $component->get('appliedFilters');
        $this->assertEquals(now()->startOfMonth()->format('Y-m-d'), $appliedFilters['startDate']);

        // Change preset
        $component->set('periodPreset', 'today');
            
        // appliedFilters should not have updated yet
        $appliedFiltersAfterSet = $component->get('appliedFilters');
        $this->assertEquals(now()->startOfMonth()->format('Y-m-d'), $appliedFiltersAfterSet['startDate']);
        if (now()->startOfMonth()->format('Y-m-d') !== now()->format('Y-m-d')) {
            $this->assertNotEquals(now()->format('Y-m-d'), $appliedFiltersAfterSet['startDate']); // assuming today is not start of month
        }

        // Apply filters
        $component->call('applyFilters')
            ->assertSet('filterTriggered', true);
            
        $appliedFiltersApplied = $component->get('appliedFilters');
        $this->assertEquals(now()->format('Y-m-d'), $appliedFiltersApplied['startDate']);
        $this->assertEquals('today', $appliedFiltersApplied['periodPreset']);
    }

    public function test_cancel_filters_restores_behavior()
    {
        $component = Livewire::actingAs($this->user)
            ->test(SaleReport::class)
            ->set('periodPreset', 'today')
            ->call('applyFilters');

        // Change it
        $component->set('periodPreset', 'this_year');
        $startOfYear = now()->startOfYear()->format('Y-m-d');
        $component->assertSet('startDate', $startOfYear);
        
        // Cancel should revert
        $component->call('cancelFilters');
        
        $component->assertSet('periodPreset', 'today')
            ->assertSet('startDate', now()->format('Y-m-d'));
    }

    public function test_reset_filters_restores_current_month()
    {
        $component = Livewire::actingAs($this->user)
            ->test(SaleReport::class)
            ->set('periodPreset', 'today')
            ->call('applyFilters');

        $component->call('resetFilters');
        
        $component->assertSet('periodPreset', '')
            ->assertSet('startDate', now()->startOfMonth()->format('Y-m-d'))
            ->assertSet('endDate', now()->endOfMonth()->format('Y-m-d'));
    }

    public function test_behavior_works_with_is_global_true()
    {
        $component = Livewire::actingAs($this->user)
            ->test(SaleReport::class, ['isGlobal' => true])
            ->set('periodPreset', 'today')
            ->call('applyFilters');
            
        $appliedFilters = $component->get('appliedFilters');
        $this->assertTrue($appliedFilters['isGlobal']);
    }
}
