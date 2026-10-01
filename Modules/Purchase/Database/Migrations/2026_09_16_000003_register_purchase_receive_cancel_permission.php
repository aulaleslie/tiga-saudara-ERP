<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers the purchases.receive.cancel permission defined in app/Config/Permissions.php.
 * Only the Admin role receives it automatically (matching the existing convention that
 * Admin holds every permission); every other role must be granted it explicitly.
 */
return new class extends Migration
{
    private const GUARD = 'web';

    private const PERMISSIONS = [
        'purchases.receive.cancel',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => self::GUARD]);
        }

        Role::where('name', 'Admin')->where('guard_name', self::GUARD)->first()?->givePermissionTo(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', self::GUARD)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
