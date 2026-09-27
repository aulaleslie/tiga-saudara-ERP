<?php

namespace Modules\Reports\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockInsightsAccessTest extends TestCase
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
        $permission = Permission::firstOrCreate(['name' => 'stockInsights.access', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);
    }

    /** @test */
    public function it_denies_route_access_without_permission()
    {
        $userWithoutPermission = User::factory()->create();
        $userWithoutPermission->settings()->attach($this->setting->id, ['role_id' => Role::firstOrCreate(['name' => 'Staff'])->id]);

        $response = $this->actingAs($userWithoutPermission)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('reports.stock-insights.index'));

        $response->assertForbidden();
    }

    /** @test */
    public function it_allows_route_access_with_permission()
    {
        $this->user->givePermissionTo('stockInsights.access');

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('reports.stock-insights.index'));

        $response->assertOk();
        $response->assertSeeLivewire('reports.stock-insights');
    }

    /** @test */
    public function unauthorized_user_cannot_mount_livewire_component()
    {
        $userWithoutPermission = User::factory()->create();

        $this->actingAs($userWithoutPermission);

        Livewire::test('reports.stock-insights')
            ->assertForbidden();
    }

    /** @test */
    public function unauthorized_user_cannot_call_update_minimum_stock_action()
    {
        $this->user->givePermissionTo('stockInsights.access');
        $this->actingAs($this->user);

        $component = Livewire::test('reports.stock-insights-minimum-modal');

        $this->user->revokePermissionTo('stockInsights.access');

        $component->call('openMinimumModal', 1, now()->subDays(6)->format('Y-m-d'), '7 Hari Terakhir')
            ->assertForbidden();
    }
}
