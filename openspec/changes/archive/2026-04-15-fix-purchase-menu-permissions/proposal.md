## Why

The user correctly pointed out the purpose of granular permissions: having access to "Penerimaan Barang" (Purchase Receivings) should not automatically imply access to "Pembelian" (Purchases). We conducted a thorough audit across the application (`menu.blade.php`, `PurchaseController.php`, `PurchaseUploadController.php`, `web.php` and different view files) to ensure this granularity is perfectly aligned from role assignment to gate checks in both controllers and views.

During the audit, we found several inconsistencies:
1. "Semua Pembelian" list page is guarded by `purchases.create` instead of `purchases.access`.
2. The parent `Pembelian` dropdown checks for access permissions (`purchases.access`, `purchaseReturns.access`) but misses the create permissions. If a user only has `purchases.create` (granularly), they won't even see the dropdown.
3. The `showReceivings` method in `PurchaseController` (which is used to view the list of received notes for a specific purchase on the datatable) is improperly guarded by `purchases.receive` instead of `purchaseReceivings.access`.
4. The Action dropdown menus in `actions.blade.php` and `actions-receiving.blade.php` display the "Menerima" link to anyone, instead of wrapping it in `@can('purchases.receive')`.
5. `Modules/PurchasesReturn/Database/Seeders/PermissionsTableSeeder.php` operates outside the centralized config, creating an orphan permission (`purchaseReturnSettlements.reject`) and assigning it to "Super Admin", bypassing the single-source-of-truth architecture.
6. The permission `purchases.receive` ("Terima") is grouped under the "Purchases" card, while `purchaseReceivings.access` is under a separate "Purchase Receivings" card. This causes confusion in the Role assignment UI.
7. If a user only has `purchases.receive`, they cannot see the parent "Pembelian" menu, nor can they access the "Penerimaan Barang" UI because it lacks the appropriate grouped checks.

## What Changes

- Fix the permission guard on "Semua Pembelian" menu item from `purchases.create` to `purchases.access`.
- Update `app/Config/Permissions.php` by changing the label "Purchase Receivings" to "Penerimaan Barang" (Bahasa Indonesia).
- Move `purchases.receive` out of the "Purchases" block and into the "Penerimaan Barang" block so all receiving actions are grouped in one card.
- Update the parent "Pembelian" dropdown `@canany` to include `purchases.create`, `purchaseReturns.create`, and `purchases.receive`.
- Update the "Penerimaan Barang" dropdown item in `menu.blade.php` to be visible if the user has `@canany(['purchaseReceivings.access', 'purchases.receive'])`.
- Change `receivingIndex` and `receivingsList` in `PurchaseController.php` to allow access if `Gate::any(['purchaseReceivings.access', 'purchases.receive'])`.
- Change `showReceivings` in `PurchaseController.php` to use `purchaseReceivings.access` instead of `purchases.receive`.
- Wrap the "Menerima" buttons in `actions.blade.php` and `actions-receiving.blade.php` with `@can('purchases.receive')`.
- Neutralize the rogue `PurchasesReturn` module seeder to defer completely to `app/Config/Permissions.php`.

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- **Permission Granularity**: Enforce complete structural integrity for `purchases` and `purchaseReceivings` so that individual permissions independently operate UI components without leakage.

## Impact

- `resources/views/layouts/menu.blade.php`
- `app/Config/Permissions.php`
- `Modules/Purchase/Http/Controllers/PurchaseController.php`
- `Modules/Purchase/Resources/views/partials/actions.blade.php`
- `Modules/Purchase/Resources/views/partials/actions-receiving.blade.php`
- `Modules/PurchasesReturn/Database/Seeders/PermissionsTableSeeder.php`
