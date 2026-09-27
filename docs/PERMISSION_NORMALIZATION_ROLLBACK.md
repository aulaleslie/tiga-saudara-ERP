# Purchase Permission Normalization - Rollback Guide

## Overview

This document provides instructions for rolling back the purchase permission normalization changes if access regression or critical issues are detected in production.

## Changes Made

### Permission Renames
- `purchases.edit` → `purchases.update`
- `purchaseReturns.edit` → `purchaseReturns.update`
- `purchaseReceivings.access` → `purchases.receive.access`
- `purchaseReceivings.approval` → `purchases.receive.approval`

### Permissions Added
- `purchases.archive` (was undefined, now explicit)
- `purchaseReturns.archive` (was undefined, now explicit)

### Permissions Removed from Config
- `purchases.view` (consolidated with `purchases.show`)
- Old receiving permissions (consolidated under purchases domain)

### Code Changes
- All controller `Gate::denies()` calls updated to use canonical keys
- All Blade `@can()` checks updated to use canonical keys
- All test files updated to use canonical keys

## Rollback Steps

### Step 1: Restore Legacy Configuration

Edit `app/Config/Permissions.php` to restore legacy permission names:

```php
'Pembelian' => [
    'purchases.access' => 'Hak Akses',
    'purchases.create' => 'Buat',
    'purchases.import' => 'Impor',
    'purchases.edit' => 'Ubah',           // Changed from purchases.update
    'purchases.delete' => 'Hapus',
    'purchases.show' => 'Tampilkan',
    'purchases.approval' => 'Persetujuan',
    'purchases.view' => 'Lihat',           // Restored (removed in new version)
],

'Penerimaan Barang' => [
    'purchaseReceivings.access' => 'Hak Akses',        // Restored
    'purchaseReceivings.approval' => 'Persetujuan',    // Restored
    'purchases.receive' => 'Terima',
],

'Retur Pembelian' => [
    'purchaseReturns.access' => 'Hak Akses',
    'purchaseReturns.create' => 'Buat',
    'purchaseReturns.edit' => 'Ubah',           // Changed from purchaseReturns.update
    'purchaseReturns.delete' => 'Hapus',
    'purchaseReturns.show' => 'Tampilkan',
    'purchaseReturns.viewPrice' => 'Lihat Harga',
    'purchaseReturns.approval' => 'Persetujuan',
    'purchaseReturns.dispatchRequest' => 'Permintaan Pengiriman',
    'purchaseReturns.dispatchApproval' => 'Persetujuan Pengiriman',
    'purchaseReturns.dispatchExecute' => 'Eksekusi Pengiriman',
    // Remove purchases.archive and purchaseReturns.archive
],
```

### Step 2: Revert Code Changes

Use git to revert code changes to the versions before 2026-04-22:

```bash
# View commit history
git log --oneline --since="2026-04-21" --until="2026-04-23"

# Revert specific files to previous versions
git checkout <commit-before-normalization> -- \
  Modules/Purchase/Http/Controllers/PurchaseController.php \
  Modules/Purchase/Http/Controllers/PurchaseUploadController.php \
  Modules/Purchase/Resources/views/partials/actions.blade.php \
  Modules/Purchase/Resources/views/show.blade.php \
  Modules/Purchase/Resources/views/receiving/index.blade.php \
  Modules/Purchase/Resources/views/receiving/list.blade.php \
  Modules/PurchasesReturn/Http/Controllers/PurchasesReturnController.php \
  Modules/PurchasesReturn/Resources/views/ \
  Modules/Purchase/Tests/ \
  tests/Feature/

# Or revert the entire commit if it's atomic
git revert <commit-hash>
```

### Step 3: Reverse the Migration

Create a new migration to reverse the permission remap:

```bash
php artisan make:migration rollback_purchase_permission_normalization --path=database/migrations
```

Content for the rollback migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        // Mapping: canonical → legacy (for rollback)
        $permissionMapping = [
            'purchases.update' => 'purchases.edit',
            'purchaseReturns.update' => 'purchaseReturns.edit',
            'purchases.receive.access' => 'purchaseReceivings.access',
            'purchases.receive.approval' => 'purchaseReceivings.approval',
        ];

        foreach (Role::all() as $role) {
            foreach ($permissionMapping as $canonicalKey => $legacyKey) {
                if ($role->hasPermissionTo($canonicalKey)) {
                    $legacyPermission = Permission::firstOrCreate(['name' => $legacyKey]);
                    $role->givePermissionTo($legacyPermission);
                }
            }
        }

        $userModel = config('auth.providers.users.model');
        if (class_exists($userModel)) {
            foreach ($userModel::all() as $user) {
                foreach ($permissionMapping as $canonicalKey => $legacyKey) {
                    if ($user->hasDirectPermission($canonicalKey)) {
                        $legacyPermission = Permission::firstOrCreate(['name' => $legacyKey]);
                        $user->givePermissionTo($legacyPermission);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Rollback migration completed via manual reapplication
    }
};
```

### Step 4: Re-sync Permissions

After reverting code and config, run the permissions seeder to restore legacy permissions and remove canonical ones:

```bash
php artisan migrate:rollback --path=database/migrations/2026_04_22_000001_normalize_purchase_permissions.php
php artisan db:seed --class=PermissionsTableSeeder
```

This will:
1. Remove canonical permissions from the database
2. Restore legacy permissions from the config
3. Rebuild Admin role with legacy permissions

### Step 5: Verify Access

After rollback, test the following:

1. **Purchase Access**: User with `purchases.access` can list purchases
2. **Purchase Edit**: User with `purchases.edit` can edit purchases
3. **Purchase Receive**: User with `purchases.receive` can receive goods
4. **Receiving Approval**: User with `purchaseReceivings.approval` can approve received notes
5. **Purchase Return Edit**: User with `purchaseReturns.edit` can edit returns
6. **All legacy workflows** function as before

## Emergency Access Restoration

If after rollback, users still lack access:

### Manual Role Reset

```php
// In tinker or command
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

$adminRole = Role::findByName('Admin');
$adminRole->syncPermissions(Permission::all());

// For specific roles:
$role = Role::findByName('PurchaseCashier');
$role->givePermissionTo(['purchases.access', 'purchases.edit', 'purchases.receive', 'purchaseReceivings.approval']);
```

### Direct User Permission Grant

```php
use App\Models\User;
use Spatie\Permission\Models\Permission;

$user = User::find($userId);
$user->givePermissionTo(Permission::where('name', 'purchases.edit')->first());
```

## Prevention & Lessons Learned

1. **Always test role assignment before production deployment**
2. **Run permission sync seeder in staging environment first**
3. **Monitor user access logs for permission denial (403) errors**
4. **Keep legacy and canonical keys in sync during transition period**
5. **Use feature flags to gate new permission system during rollout**

## Timeline

- **2026-04-22**: Permission normalization migration deployed
- **If regression detected**: Execute immediate rollback (steps 1-4 above)
- **Post-rollback**: Analyze root cause and plan safer transition

## Contact

For assistance with permission rollback:
- Database Admin: Check role_has_permissions table for inconsistencies
- DevOps: Verify Spatie permission cache is cleared after migration
- QA: Validate all purchase/return workflows after rollback

