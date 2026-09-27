## Why

The current mapping of Sales and Sales Return permissions in `app/Config/Permissions.php` is scattered and incomplete. This fragmentation makes it difficult for administrators to accurately assign roles via the UI. Certain permissions used natively by the application (e.g. `sales.archive`, `saleReturns.archive`, and `sales.approved.edit`) are entirely missing from the config, rendering them un-assignable, while unused permissions clutter the UI.

## What Changes

- Group all Sales and Sales Returns permissions sequentially inside `app/Config/Permissions.php` to ensure the UI renders the modules adjacent to one another.
- Register missing but actively gated permissions to the configuration:
  - `sales.approved.edit`
  - `sales.archive`
  - `saleReturns.archive`
- Remove unused or obsolete permissions:
  - `saleReturnPayments.show`
- Re-run the `PermissionsTableSeeder` logic/migrator manually or document the need to run it so these new permission keys propagate into the `permissions` table dynamically.

## Capabilities

### New Capabilities
- `sales-permission-normalization`: Normalizes and groups all permission declarations for Sales, Dispatch, Sales Returns, and their associated Settlements & Payments to ensure 100% UI coverage and coherence.

### Modified Capabilities

## Impact

- `app/Config/Permissions.php`: Changed array structure, grouped by Sales/Returns blocks.
- `PermissionsTableSeeder`: Will need to be executed to inject the missing permissions into the database and assign them to the super-admin role.
- Roles Creation/Edit UI (auto-adapts via `PermissionHelper`).
