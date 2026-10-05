<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const GUARD = 'web';

    private const PERMISSIONS = [
        'cashDenominations.access',
        'cashDenominations.edit',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => self::GUARD,
            ]);
        }

        Role::query()
            ->where('name', 'Admin')
            ->where('guard_name', self::GUARD)
            ->first()
            ?->givePermissionTo(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()
            ->where('guard_name', self::GUARD)
            ->whereIn('name', self::PERMISSIONS)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
