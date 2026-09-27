## Context

The application uses a centralized permission architecture:
1. `app/Config/Permissions.php` — single source of truth for all permission definitions
2. `Modules/User/Database/Seeders/PermissionsTableSeeder.php` — syncs DB to config
3. `Modules/User/Helpers/PermissionHelper::getGroupsForForm()` — reads from config for role UI
4. Controllers and Views — use `Gate` and `@can` / `@canany` directives.

A thorough audit revealed that several blade view guards and one controller endpoint lacked strict permission granularity for `purchaseReceivings` versus `purchases`, alongside an orphan permission from a rogue seeder.

## Goals / Non-Goals

**Goals:**
- Fix `menu.blade.php` to correctly respect granular create/access permissions for the "Pembelian" dropdown.
- Correct the guard on "Semua Pembelian" list.
- Enhance security by strictly enforcing `purchases.receive` on "Menerima" action buttons.
- Correct `PurchaseController@showReceivings` to require `purchaseReceivings.access` instead of an action permission.
- Neutralize the rogue `PurchasesReturn` seeder to prevent orphan permissions.

**Non-Goals:**
- Modifying the `Permissions.php` structure.
- Altering the logic of how users save roles.

## Decisions

### 1. Fix `menu.blade.php` Dropdown and List Guards
**Decision**: 
- Update the "Pembelian" `@canany` to include `['purchases.access', 'purchases.create', 'purchaseReturns.access', 'purchaseReturns.create', 'purchaseReceivings.access']`.
- Change `@can('purchases.create')` to `@can('purchases.access')` for "Semua Pembelian".
**Rationale**: Proper granularity dictates that a user strictly assigned a "create" permission should still see the parent dropdown, and a user with "access" should be able to view the list pages.

### 2. View/Action "Menerima" Buttons
**Decision**: Wrap `<a href="{{ route('purchases.receive', $data->id) }}">` buttons in `actions.blade.php` and `actions-receiving.blade.php` within `@can('purchases.receive')`.
**Rationale**: Even if a user can access the list, "Menerima" is an action that strictly triggers the receiving workflow and thus requires explicit action permission.

### 3. Change `showReceivings` Guard
**Decision**: Replace `abort_if(Gate::denies('purchases.receive'), 403)` with `purchaseReceivings.access` in `PurchaseController@showReceivings`.
**Rationale**: The `showReceivings` endpoint merely returns a data table of already-received notes. Viewing them should not require action permission (`purchases.receive`), only listing module permission (`purchaseReceivings.access`).

### 4. Neutralize Rogue Seeder
**Decision**: Replace the body of `PurchasesReturn/.../PermissionsTableSeeder.php` with a no-op.
**Rationale**: The centralized seeder in `Modules/User` correctly handles all permissions from `Permissions.php`, so this extra file creates stray entries.

## Risks / Trade-offs

- **[Risk]** The orphan `purchaseReturnSettlements.reject` may still exist in the DB after this change.
  - **Mitigation**: Running the centralized `PermissionsTableSeeder` will auto-delete it (it purges permissions not in config).

