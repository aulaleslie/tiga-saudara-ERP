<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionsTableSeeder extends Seeder
{
    private const DEFAULT_GUARD = 'web';

    /**
     * Run the database seeds.
     *
     * Loads all permissions from the centralized configuration (app/Config/Permissions.php)
     * and synchronizes the database to match exactly:
     * - Creates missing permissions
     * - Deletes orphaned permissions (not in config)
     * - Syncs Admin role to all configured permissions
     */
    public function run()
    {
        // Load permissions from centralized configuration
        $permissionsConfig = config('permissions');

        // Flatten the grouped config into a simple permission list
        $permissions = [];
        foreach ($permissionsConfig as $group => $groupPermissions) {
            $permissions = array_merge($permissions, array_keys($groupPermissions));
        }

        // Normalize & dedupe
        $permissions = array_values(array_unique($permissions));

        $this->info("Syncing " . count($permissions) . " permissions from configuration...");

        DB::transaction(function () use ($permissions) {
            // Create missing permissions
            $createdCount = 0;
            foreach ($permissions as $permission) {
                $created = Permission::firstOrCreate([
                    'name' => $permission,
                    'guard_name' => self::DEFAULT_GUARD,
                ]);
                if ($created->wasRecentlyCreated) {
                    $createdCount++;
                }
            }

            if ($createdCount > 0) {
                $this->info("Created $createdCount new permissions");
            }

            // Delete permissions not in config (orphaned)
            $deletedCount = Permission::whereNotIn('name', $permissions)->count();
            if ($deletedCount > 0) {
                Permission::whereNotIn('name', $permissions)->delete();
                $this->info("Deleted $deletedCount orphaned permissions");
            }

            // Sync Admin role to exactly this set
            $role = Role::firstOrCreate([
                'name' => 'Admin',
                'guard_name' => self::DEFAULT_GUARD,
            ]);
            $role->syncPermissions($permissions);
            $this->info("Synced Admin role to all " . count($permissions) . " permissions");
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->info("Permission synchronization complete.");
    }

    private function info(string $message): void
    {
        if ($this->command !== null) {
            $this->command->info($message);
        }
    }
}
