<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Services\TransferStockVisibility;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransferMovementPermissionCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'web';

    protected function setUp(): void
    {
        parent::setUp();

        $allPermissions = [
            'stockTransfers.access',
            'stockTransfers.create',
            'stockTransfers.edit',
            'stockTransfers.show',
            'stockTransfers.approval',
            'stockTransfers.dispatch',
            'stockTransfers.receive',
            'stockTransfers.archive',
            TransferStockVisibility::PERMISSION,
        ];

        foreach ($allPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => self::GUARD]);
        }
    }

    /** @test */
    public function migration_synchronizes_legacy_roles_to_new_movement_permissions_without_granting_stock_visibility(): void
    {
        $dispatchRole = Role::create(['name' => 'LegacyDispatcher', 'guard_name' => self::GUARD]);
        $dispatchRole->givePermissionTo('stockTransfers.dispatch');

        $receiveRole = Role::create(['name' => 'LegacyReceiver', 'guard_name' => self::GUARD]);
        $receiveRole->givePermissionTo('stockTransfers.receive');

        // Execute the real migration instance
        $migration = require base_path('Modules/Adjustment/Database/Migrations/2026_09_13_180002_sync_stock_transfer_movement_permissions.php');
        $migration->up();

        $dispatchRole = Role::with('permissions')->find($dispatchRole->id);
        $receiveRole = Role::with('permissions')->find($receiveRole->id);

        $this->assertTrue($dispatchRole->hasPermissionTo('stockTransfers.dispatch.create'));
        $this->assertTrue($dispatchRole->hasPermissionTo('stockTransfers.dispatch.approval'));
        $this->assertFalse($dispatchRole->hasPermissionTo('stockTransfers.receive.create'));

        $this->assertTrue($receiveRole->hasPermissionTo('stockTransfers.receive.create'));
        $this->assertTrue($receiveRole->hasPermissionTo('stockTransfers.receive.approval'));
        $this->assertFalse($receiveRole->hasPermissionTo('stockTransfers.dispatch.create'));

        $this->assertFalse($dispatchRole->hasPermissionTo(TransferStockVisibility::PERMISSION));
        $this->assertFalse($receiveRole->hasPermissionTo(TransferStockVisibility::PERMISSION));
    }

    /** @test */
    public function movement_permissions_do_not_grant_stock_visibility(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'stockTransfers.access',
            'stockTransfers.dispatch.create',
            'stockTransfers.dispatch.approval',
            'stockTransfers.receive.create',
            'stockTransfers.receive.approval',
        ]);

        $this->assertFalse(TransferStockVisibility::canView($user));
    }
}
