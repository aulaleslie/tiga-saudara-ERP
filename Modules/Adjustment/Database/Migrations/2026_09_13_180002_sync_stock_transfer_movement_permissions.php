<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const DEFAULT_GUARD = 'web';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Ensure permissions exist
        $newPermissions = [
            'stockTransfers.dispatch.create',
            'stockTransfers.dispatch.approval',
            'stockTransfers.receive.create',
            'stockTransfers.receive.approval',
        ];

        foreach ($newPermissions as $permName) {
            Permission::firstOrCreate([
                'name' => $permName,
                'guard_name' => self::DEFAULT_GUARD,
            ]);
        }

        // 2. Compatibility sync:
        // Roles with stockTransfers.dispatch -> get dispatch.create and dispatch.approval
        // Roles with stockTransfers.receive  -> get receive.create and receive.approval
        $roles = Role::with('permissions')->where('guard_name', self::DEFAULT_GUARD)->get();

        foreach ($roles as $role) {
            if ($role->hasPermissionTo('stockTransfers.dispatch', self::DEFAULT_GUARD)) {
                $role->givePermissionTo(['stockTransfers.dispatch.create', 'stockTransfers.dispatch.approval']);
            }

            if ($role->hasPermissionTo('stockTransfers.receive', self::DEFAULT_GUARD)) {
                $role->givePermissionTo(['stockTransfers.receive.create', 'stockTransfers.receive.approval']);
            }
        }

        // 3. Ensure Admin role has all permissions including the new ones
        $adminRole = Role::where('name', 'Admin')->where('guard_name', self::DEFAULT_GUARD)->first();
        if ($adminRole) {
            $adminRole->givePermissionTo($newPermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $newPermissions = [
            'stockTransfers.dispatch.create',
            'stockTransfers.dispatch.approval',
            'stockTransfers.receive.create',
            'stockTransfers.receive.approval',
        ];

        Permission::whereIn('name', $newPermissions)
            ->where('guard_name', self::DEFAULT_GUARD)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
