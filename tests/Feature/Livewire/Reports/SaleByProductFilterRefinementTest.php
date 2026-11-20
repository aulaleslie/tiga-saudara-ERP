<?php

namespace Tests\Feature\Livewire\Reports;

use App\Livewire\Reports\SaleByProductReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleByProductFilterRefinementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Bisnis Utama',
            'company_email' => 'b1@example.com',
            'company_phone' => '0811111111',
            'notification_email' => 'b1@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'F1',
            'company_address' => 'Addr 1',
        ]);

        $this->user = User::factory()->create(['is_active' => 1]);
        $perm = Permission::firstOrCreate(['name' => 'saleReports.access']);
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $role->givePermissionTo($perm);
        $this->user->assignRole($role);
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function it_computes_asia_makassar_week_month_and_year_rollover_presets(): void
    {
        // March 31, 2026 (Tuesday) in Asia/Makassar
        Carbon::setTestNow(Carbon::create(2026, 3, 31, 10, 0, 0, 'Asia/Makassar'));

        Livewire::test(SaleByProductReport::class)
            ->assertSet('periodPreset', 'this_month')
            ->assertSet('startDate', '2026-03-01')
            ->assertSet('endDate', '2026-03-31')
            ->set('periodPreset', 'last_month')
            ->assertSet('periodPreset', 'last_month')
            ->assertSet('startDate', '2026-02-01')
            ->assertSet('endDate', '2026-02-28')
            ->set('periodPreset', 'this_week')
            ->assertSet('periodPreset', 'this_week')
            ->assertSet('startDate', '2026-03-30') // Monday
            ->assertSet('endDate', '2026-04-05')   // Sunday
            ->set('periodPreset', 'last_week')
            ->assertSet('periodPreset', 'last_week')
            ->assertSet('startDate', '2026-03-23') // Monday
            ->assertSet('endDate', '2026-03-29')   // Sunday
            ->set('periodPreset', 'last_year')
            ->assertSet('periodPreset', 'last_year')
            ->assertSet('startDate', '2025-01-01')
            ->assertSet('endDate', '2025-12-31');

        // January 5, 2026 -> last_month rolls over to December 2025
        Carbon::setTestNow(Carbon::create(2026, 1, 5, 10, 0, 0, 'Asia/Makassar'));

        Livewire::test(SaleByProductReport::class)
            ->set('periodPreset', 'last_month')
            ->assertSet('startDate', '2025-12-01')
            ->assertSet('endDate', '2025-12-31');
    }

    /** @test */
    public function manual_date_edits_switch_preset_to_custom_and_require_apply_before_export(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 10, 0, 0, 'Asia/Makassar'));

        Livewire::test(SaleByProductReport::class)
            ->call('applyFilters')
            ->assertSet('hasPendingFilterChanges', false)
            ->set('startDate', '2026-11-05')
            ->assertSet('periodPreset', 'custom')
            ->assertSet('hasPendingFilterChanges', true)
            ->call('exportExcel')
            ->assertDispatched('alert', [
                'type' => 'error',
                'message' => 'Kondisi filter telah berubah. Silakan terapkan filter kembali sebelum mengekspor.',
            ]);
    }

    /** @test */
    public function manual_dates_recognize_exact_presets_and_render_the_selected_option(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 10, 0, 0, 'Asia/Makassar'));

        $component = Livewire::test(SaleByProductReport::class)
            ->assertSeeHtml('wire:model.lazy="periodPreset"')
            ->assertSeeHtml('wire:model.lazy="startDate"')
            ->assertSeeHtml('wire:model.lazy="endDate"')
            ->assertDontSeeHtml('wire:model.change=')
            ->call('applyFilters');
        $applied = $component->get('appliedFilters');
        $ranges = [
            'today' => ['2026-11-21', '2026-11-21'],
            'this_week' => ['2026-11-16', '2026-11-22'],
            'last_week' => ['2026-11-09', '2026-11-15'],
            'this_month' => ['2026-11-01', '2026-11-30'],
            'last_month' => ['2026-10-01', '2026-10-31'],
            'this_year' => ['2026-01-01', '2026-12-31'],
            'last_year' => ['2025-01-01', '2025-12-31'],
        ];

        foreach ($ranges as $preset => [$start, $end]) {
            $component->set('startDate', $start)->set('endDate', $end)
                ->assertSet('periodPreset', $preset)
                ->assertSeeHtml('wire:key="sale-by-product-period-' . $preset . '"')
                ->assertSeeHtml('value="' . $preset . '" selected');
        }

        $component->set('startDate', '2026-01-02')->set('endDate', '2026-12-31')
            ->assertSet('periodPreset', 'custom')
            ->assertSeeHtml('value="custom" selected')
            ->assertSet('appliedFilters', $applied)
            ->assertSet('hasPendingFilterChanges', true);
    }

    /** @test */
    public function choosing_custom_preserves_dates_and_cancel_restores_the_applied_period(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 10, 0, 0, 'Asia/Makassar'));

        Livewire::test(SaleByProductReport::class)
            ->set('periodPreset', 'this_year')
            ->call('applyFilters')
            ->set('periodPreset', 'custom')
            ->assertSet('startDate', '2026-01-01')
            ->assertSet('endDate', '2026-12-31')
            ->set('endDate', '2026-12-30')
            ->assertSet('periodPreset', 'custom')
            ->call('cancelFilters')
            ->assertSet('periodPreset', 'this_year')
            ->assertSet('startDate', '2026-01-01')
            ->assertSet('endDate', '2026-12-31')
            ->assertSeeHtml('value="this_year" selected')
            ->assertSet('hasPendingFilterChanges', false);
    }

    /** @test */
    public function customer_checkbox_multi_select_preserves_search_caps_at_25_and_keeps_applied_summary_stable(): void
    {
        $customers = [];
        for ($i = 1; $i <= 28; $i++) {
            $customers[] = Customer::create([
                'customer_name' => "PT Pelanggan Multi {$i}",
                'contact_name' => "Kontak {$i}",
                'customer_email' => "c{$i}@example.com",
                'customer_phone' => "08100000{$i}",
                'city' => 'Makassar',
                'country' => 'Indonesia',
                'address' => 'Jl. Test',
                'setting_id' => $this->setting->id,
            ]);
        }

        $c1 = $customers[0];
        $c2 = $customers[1];
        $c3 = $customers[2];

        $component = Livewire::test(SaleByProductReport::class)
            ->set('customerSearch', 'pelanggan multi')
            ->assertSet('hasMoreCustomerOptions', true)
            ->assertCount('customerOptions', 25)
            ->call('toggleCustomer', $c1->id, $c1->canonical_name)
            ->call('toggleCustomer', $c2->id, $c2->canonical_name)
            ->assertSet('customerSearch', 'pelanggan multi') // search preserved while checking
            ->assertSet('customerIds', [$c1->id, $c2->id])
            ->call('applyFilters')
            ->assertSee('Customer (2):')
            ->assertSee($c1->canonical_name)
            // Draft change must NOT alter applied summary until Apply
            ->call('toggleCustomer', $c3->id, $c3->canonical_name)
            ->assertSet('hasPendingFilterChanges', true)
            ->assertSee('Customer (2):')
            // Cancel restores applied selection
            ->call('cancelFilters')
            ->assertSet('customerIds', [$c1->id, $c2->id])
            ->assertSet('hasPendingFilterChanges', false)
            // Reset clears draft selection
            ->call('resetFilters')
            ->assertSet('customerIds', [])
            ->assertSet('periodPreset', 'this_month');

        $this->assertNotNull($component);
    }
}
