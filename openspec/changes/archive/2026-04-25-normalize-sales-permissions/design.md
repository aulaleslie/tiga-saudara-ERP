## Context

The system relies on `app/Config/Permissions.php` to define and map access control permissions across the application. These keys are read by `PermissionHelper` to build the Role Management interface. Currently, several actively enforced permissions in the Sales and Sales Return modules are undocumented in this config, causing a disconnect where capabilities are gated in code but un-assignable in the UI. Furthermore, the grouping of Sales-related modules is scattered, creating a fragmented administrative experience when managing roles.

## Goals / Non-Goals

**Goals:**
- Completely align the Sales, Dispatch, and Sales Return permissions declared in code with those exposed in the UI.
- Structure the configuration logically so related modules ("Penjualan", "Pengiriman Penjualan", "Retur Penjualan", etc.) appear in close proximity within the role management cards.
- Ensure the super-admin role automatically receives the newly documented permissions.

**Non-Goals:**
- Refactoring `PermissionHelper` or the Blade UI components layout rendering logic.
- Altering or introducing any new `Gate::denies` or `$user->can()` checks within the active application code; this change strictly reconciles the config with the existing application state.

## Decisions

1. **Re-ordering `app/Config/Permissions.php` Arrays**:
   - *Alternative*: Leave the order as is and just append missing keys.
   - *Rationale*: Since `PermissionHelper::getGroupsForForm()` iterates through the config dynamically, re-ordering the array directly dictates the visual layout of the role cards, achieving the "close proximity" requirement without frontend modifications.

2. **Removing `saleReturnPayments.show`**:
   - *Alternative*: Keep it in config.
   - *Rationale*: Static analysis shows `SaleReturnPaymentsController` has no `show()` method; keeping dead code clutters the UI and causes administrative confusion.

3. **Running `PermissionsTableSeeder` via Documentation/Tasks**:
   - *Alternative*: Create a specific database migration.
   - *Rationale*: Standard Laravel Spatie permission propagation in this project uses the `db:seed --class=PermissionsTableSeeder` or the custom `artisan sync:permissions` command. The task will directly utilize this existing flow to inject database records for `sales.archive`, `saleReturns.archive`, and `sales.approved.edit`.

## Risks / Trade-offs

- **Risk**: Role configurations built before this change lack explicit assignments for the newly exposed permissions.
  - *Mitigation*: The `PermissionsTableSeeder` handles assignment to the default "Admin" base role. Other custom roles will need to be manually updated by administrators in the UI, which is intended behavior when exposing previously un-assignable granular controls.
