<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Services\TransferStockVisibility;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Proves the `stockTransfers.view-system-stock` authorization boundary
 * (task 1.2 of transfer-stock-visibility-boundary):
 *  (a) ordinary transfer workflow permissions do not imply stock visibility
 *  (b) an explicit grant enables the privileged projection
 *  (c) Super Admin receives the privileged projection via the global
 *      Gate::before bypass without a direct permission assignment
 */
class TransferStockVisibilityAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'stockTransfers.access',
            'stockTransfers.create',
            'stockTransfers.edit',
            'stockTransfers.show',
            'stockTransfers.approval',
            'stockTransfers.dispatch',
            'stockTransfers.receive',
            'stockTransfers.archive',
            TransferStockVisibility::PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    /** @test */
    public function workflow_permissions_alone_do_not_imply_stock_visibility()
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'stockTransfers.access',
            'stockTransfers.create',
            'stockTransfers.edit',
            'stockTransfers.show',
            'stockTransfers.approval',
            'stockTransfers.dispatch',
            'stockTransfers.receive',
            'stockTransfers.archive',
        ]);

        $this->assertFalse(TransferStockVisibility::canView($user));
        $this->assertFalse($user->can(TransferStockVisibility::PERMISSION));

        // workflow abilities remain intact
        $this->assertTrue($user->can('stockTransfers.create'));
        $this->assertTrue($user->can('stockTransfers.edit'));
        $this->assertTrue($user->can('stockTransfers.dispatch'));
    }

    /** @test */
    public function explicit_grant_enables_privileged_projection()
    {
        $user = User::factory()->create();
        $user->givePermissionTo(TransferStockVisibility::PERMISSION);

        $this->assertTrue(TransferStockVisibility::canView($user));
        $this->assertTrue($user->can(TransferStockVisibility::PERMISSION));
    }

    /** @test */
    public function super_admin_bypasses_explicit_assignment()
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        // no direct permission assignment
        $this->assertFalse($user->hasDirectPermission(TransferStockVisibility::PERMISSION));

        $this->assertTrue(TransferStockVisibility::canView($user));
        $this->assertTrue($user->can(TransferStockVisibility::PERMISSION));
    }

    /** @test */
    public function no_user_is_blind_by_default()
    {
        $user = User::factory()->create();

        $this->assertFalse(TransferStockVisibility::canView($user));
    }
}
