<?php

namespace Modules\Reports\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\User\Database\Seeders\PermissionsTableSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockInsightsPermissionRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function stock_insights_access_is_registered_in_permissions_config_and_synced_by_seeder()
    {
        $config = config('permissions');
        $this->assertArrayHasKey('Laporan Pantauan Stok', $config);
        $this->assertArrayHasKey('stockInsights.access', $config['Laporan Pantauan Stok']);
        $this->assertEquals('Hak Akses', $config['Laporan Pantauan Stok']['stockInsights.access']);

        $this->seed(PermissionsTableSeeder::class);

        $permission = Permission::where('name', 'stockInsights.access')->where('guard_name', 'web')->first();
        $this->assertNotNull($permission);

        $adminRole = Role::where('name', 'Admin')->first();
        $this->assertNotNull($adminRole);
        $this->assertTrue($adminRole->hasPermissionTo('stockInsights.access'));

        $user = User::factory()->create();
        $this->assertFalse($user->can('stockInsights.access'));

        $user->givePermissionTo('stockInsights.access');
        $this->assertTrue($user->fresh()->can('stockInsights.access'));
    }
}
