<?php

namespace Modules\Reports\Tests\Feature;

use Modules\Setting\Entities\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarehouseStockValuationReportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->setting = Setting::factory()->create();
        $this->user = User::factory()->create();
        
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $permission = Permission::firstOrCreate(['name' => 'inventoryValuationReports.access', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);
    }

    /** @test */
    public function it_denies_access_without_permission()
    {
        $userWithoutPermission = User::factory()->create();
        $userWithoutPermission->settings()->attach($this->setting->id, ['role_id' => Role::firstOrCreate(['name' => 'Staff'])->id]);
        
        $response = $this->actingAs($userWithoutPermission)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('reports.warehouse-stock-valuation.index'));

        $response->assertForbidden();
    }

    /** @test */
    public function it_allows_access_with_permission()
    {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'inventoryValuationReports.access', 'guard_name' => 'web']);
        $this->user->givePermissionTo('inventoryValuationReports.access');

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('reports.warehouse-stock-valuation.index'));

        $response->assertOk();
        $response->assertSeeLivewire('reports.warehouse-stock-valuation-report');
    }

}
