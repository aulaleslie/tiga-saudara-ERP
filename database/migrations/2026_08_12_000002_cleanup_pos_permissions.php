<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const POS_PERMISSION_NAMES = [
        'pos.access',
        'pos.create',
        'pos.transactions.access',
        'pos.drafts.view',
        'pos.drafts.update',
        'pos.drafts.submit',
        'pos.drafts.void',
        'pos.drafts.lock.override',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $permissionIds = DB::table('permissions')
                ->whereIn('name', self::POS_PERMISSION_NAMES)
                ->pluck('id');

            if ($permissionIds->isEmpty()) {
                return;
            }

            DB::table('role_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();

            DB::table('model_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();

            DB::table('permissions')
                ->whereIn('id', $permissionIds)
                ->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Forward-only migration.
    }
};
