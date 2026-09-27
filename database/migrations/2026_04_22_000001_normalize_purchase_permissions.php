<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        // Create a mapping of legacy keys to canonical keys
        $permissionMapping = [
            'purchases.edit' => 'purchases.update',
            'purchaseReturns.edit' => 'purchaseReturns.update',
            'purchaseReceivings.access' => 'purchases.receive.access',
            'purchaseReceivings.approval' => 'purchases.receive.approval',
        ];

        // Get all roles and remap their permissions
        foreach (Role::all() as $role) {
            foreach ($permissionMapping as $legacyKey => $canonicalKey) {
                // Check if role has the legacy permission
                if ($role->hasPermissionTo($legacyKey)) {
                    // Get or create the canonical permission
                    $canonicalPermission = Permission::firstOrCreate(['name' => $canonicalKey]);
                    
                    // Assign canonical permission to role
                    $role->givePermissionTo($canonicalPermission);
                }
            }
        }

        // Handle purchases.view → purchases.show consolidation
        // If a user has purchases.view, ensure they have purchases.show access
        $viewPermission = Permission::where('name', 'purchases.view')->first();
        if ($viewPermission) {
            $showPermission = Permission::firstOrCreate(['name' => 'purchases.show']);
            foreach (Role::all() as $role) {
                if ($role->hasPermissionTo('purchases.view')) {
                    $role->givePermissionTo($showPermission);
                }
            }
        }

        // Handle user direct permissions too
        $userModel = config('auth.providers.users.model');
        if (class_exists($userModel)) {
            foreach ($userModel::all() as $user) {
                foreach ($permissionMapping as $legacyKey => $canonicalKey) {
                    if ($user->hasDirectPermission($legacyKey)) {
                        $canonicalPermission = Permission::firstOrCreate(['name' => $canonicalKey]);
                        $user->givePermissionTo($canonicalPermission);
                    }
                }
                
                // Handle purchases.view consolidation
                if ($user->hasDirectPermission('purchases.view')) {
                    $showPermission = Permission::firstOrCreate(['name' => 'purchases.show']);
                    $user->givePermissionTo($showPermission);
                }
            }
        }
    }

    public function down(): void
    {
        // Rollback is complex - we'd need to restore the original state
        // For now, this is a one-way migration that remaps existing role assignments
        // To fully rollback, run: php artisan db:seed --class=PermissionsTableSeeder
        // with the old permissions config restored
    }
};
