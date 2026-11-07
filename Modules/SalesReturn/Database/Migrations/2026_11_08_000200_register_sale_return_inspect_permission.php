<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers saleReturns.inspect (defined in app/Config/Permissions.php). It is only REGISTERED:
 * no role or user receives it here. Assign it explicitly (role management UI) to the people who
 * may inspect and release returned goods.
 */
return new class extends Migration
{
    private const GUARD = 'web';
    private const PERMISSIONS = ['saleReturns.inspect'];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => self::GUARD]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', self::GUARD)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
