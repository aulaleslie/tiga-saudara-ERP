<?php

namespace Tests\Feature\Reports;

use App\Livewire\Reports\ProfitLossReport;
use App\Livewire\Reports\SaleByProductReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportBusinessScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $settingA;
    protected Setting $settingB;
    protected Setting $settingC;
    protected Role $adminRole;
    protected Role $superAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        Permission::firstOrCreate(['name' => 'reports.access']);

        $currency = Currency::factory()->create(['code' => 'IDR']);
        $this->settingA = Setting::factory()->create(['company_name' => 'Company A', 'default_currency_id' => $currency->id]);
        $this->settingB = Setting::factory()->create(['company_name' => 'Company B', 'default_currency_id' => $currency->id]);
        $this->settingC = Setting::factory()->create(['company_name' => 'Company C', 'default_currency_id' => $currency->id]);

        $this->adminRole = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $this->superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    }

    public function test_user_assigned_to_a_and_b_sees_only_a_and_b_in_sale_by_product_and_pl_options()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('reports.access');
        $user->settings()->attach($this->settingA->id, ['role_id' => $this->adminRole->id]);
        $user->settings()->attach($this->settingB->id, ['role_id' => $this->adminRole->id]);

        session(['setting_id' => $this->settingA->id]);

        // Test SaleByProductReport
        $saleComponent = Livewire::actingAs($user)->test(SaleByProductReport::class);
        $availableInSale = $saleComponent->viewData('availableSettings');
        $saleIds = collect($availableInSale)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->settingA->id, $this->settingB->id], $saleIds);
        $this->assertNotContains($this->settingC->id, $saleIds);

        // Test ProfitLossReport
        $plComponent = Livewire::actingAs($user)->test(ProfitLossReport::class);
        $availableInPl = $plComponent->viewData('availableSettings');
        $plIds = collect($availableInPl)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->settingA->id, $this->settingB->id], $plIds);
        $this->assertNotContains($this->settingC->id, $plIds);
    }

    public function test_tampered_id_for_c_is_dropped_in_sale_by_product_and_pl()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('reports.access');
        $user->settings()->attach($this->settingA->id, ['role_id' => $this->adminRole->id]);
        $user->settings()->attach($this->settingB->id, ['role_id' => $this->adminRole->id]);

        session(['setting_id' => $this->settingA->id]);

        // SaleByProduct with tampered ID C included
        $saleComponent = Livewire::actingAs($user)
            ->test(SaleByProductReport::class)
            ->set('selectedSettingIds', [$this->settingA->id, $this->settingC->id]);

        $this->assertEquals([$this->settingA->id], $saleComponent->instance()->getSanitizedSettingIds());

        // ProfitLoss with tampered ID C included
        $plComponent = Livewire::actingAs($user)
            ->test(ProfitLossReport::class)
            ->set('selectedSettingIds', [$this->settingA->id, $this->settingC->id]);

        $this->assertEquals([$this->settingA->id], $plComponent->instance()->getSanitizedSettingIds());
        $this->assertEquals([$this->settingA->id], $plComponent->viewData('effectiveSettingIds'));
    }

    public function test_super_admin_sees_all_businesses_in_options()
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');
        $superAdmin->givePermissionTo('reports.access');

        session(['setting_id' => $this->settingA->id]);

        // SaleByProduct
        $saleComponent = Livewire::actingAs($superAdmin)->test(SaleByProductReport::class);
        $availableInSale = $saleComponent->viewData('availableSettings');
        $saleIds = collect($availableInSale)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->settingA->id, $this->settingB->id, $this->settingC->id], $saleIds);

        // ProfitLoss
        $plComponent = Livewire::actingAs($superAdmin)->test(ProfitLossReport::class);
        $availableInPl = $plComponent->viewData('availableSettings');
        $plIds = collect($availableInPl)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->settingA->id, $this->settingB->id, $this->settingC->id], $plIds);
    }
}
