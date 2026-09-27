## 1. Structure the Permissions in `app/Config/Permissions.php`

- [x] 1.1 Open `app/Config/Permissions.php`.
- [x] 1.2 Locate the `'Purchases'` array. Remove `'purchases.receive' => 'Terima',` from it.
- [x] 1.3 Locate the `'Purchase Receivings'` array. Rename the key itself to `'Penerimaan Barang'` so it appears in Bahasa Indonesia in the UI.
- [x] 1.4 Add `'purchases.receive' => 'Terima',` to this newly renamed `'Penerimaan Barang'` array.

## 2. Fix `menu.blade.php` Guards

- [x] 2.1 Open `resources/views/layouts/menu.blade.php`.
- [x] 2.2 Locate the "Pembelian" parent dropdown `@canany` on line 306. Change it to include the missing create abilities and `purchases.receive`: `['purchases.access', 'purchases.create', 'purchases.receive', 'purchaseReturns.access', 'purchaseReturns.create', 'purchaseReceivings.access']`.
- [x] 2.3 Locate the "Semua Pembelian" list item on line 321. Change `@can('purchases.create')` to `@can('purchases.access')`.
- [x] 2.4 Locate the "Penerimaan Barang" child list item on line 330. Change `@can('purchaseReceivings.access')` to `@canany(['purchaseReceivings.access', 'purchases.receive'])`.
- [x] 2.5 Do the same for the "Daftar Penerimaan" child list item on line 339. Check `['purchaseReceivings.access', 'purchases.receive']` before rendering it.

## 3. Update Controller Gates

- [x] 3.1 Open `Modules/Purchase/Http/Controllers/PurchaseController.php`.
- [x] 3.2 In `receivingIndex()`, change the `Gate::denies` check to: `abort_unless(Gate::any(['purchaseReceivings.access', 'purchases.receive']), 403);`
- [x] 3.3 In `receivingsList()`, change the `Gate::denies` check to: `abort_unless(Gate::any(['purchaseReceivings.access', 'purchases.receive']), 403);`
- [x] 3.4 In `showReceivings()`, change `abort_if(Gate::denies('purchases.receive'), 403);` to `abort_if(Gate::denies('purchaseReceivings.access'), 403);`.

## 4. Refine Action Views for "Menerima"

- [x] 4.1 Open `Modules/Purchase/Resources/views/partials/actions.blade.php`.
- [x] 4.2 Wrap the "Menerima" `<a href>` block (around lines 161-165) in `@can('purchases.receive') ... @endcan`.
- [x] 4.3 Open `Modules/Purchase/Resources/views/partials/actions-receiving.blade.php`.
- [x] 4.4 Wrap the "Menerima" `<a href>` block (around lines 6-10) in `@can('purchases.receive') ... @endcan`.

## 5. Neutralize Rogue Seeder

- [x] 5.1 Open `Modules/PurchasesReturn/Database/Seeders/PermissionsTableSeeder.php`.
- [x] 5.2 Replace the contents of the `run()` method with a comment explaining that permissions are now handled centrally by `app/Config/Permissions.php` and `Modules/User/Database/Seeders/PermissionsTableSeeder.php`. Remove the array of permissions and the assignment logic.


## 6. Sync & Validate

- [x] 6.1 Run `php artisan db:seed --class=PermissionsTableSeeder` to sync the database with the config file and purge the orphan `purchaseReturnSettlements.reject` permission.
- [x] 6.2 Run `php artisan permission:cache-reset` to clear the Spatie permission cache.
- [x] 6.3 Validate the granularity: Ensure users with ONLY `purchases.receive` or `purchaseReceivings.access` can see the "Penerimaan Barang" module but cannot trigger any viewing actions on the rest of the Purchase module.
