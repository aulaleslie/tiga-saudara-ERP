## Why

Purchase and purchase-return permissions are currently inconsistent across config, routes, controllers, and role UI, causing missing checks, orphan checks, and confusing role assignment. We need a single, explicit permission model so authorization is predictable and maintainable.

## What Changes

- Define a normalized permission taxonomy for purchase and purchase-return actions under `/purchases/*` and `/purchase-returns/*`.
- Align authorization enforcement across route middleware, controller gates, Livewire checks, and Blade `@can` conditions to that taxonomy.
- **BREAKING**: Rename and consolidate action keys to consistent verbs (`update`, `archive`, `print`, `receive`, `approval`) and remove duplicate or unrelated keys.
- Remove dead/unused permission keys and stale checks that no longer map to reachable flows.
- Standardize role management grouping labels so purchase permissions are grouped consistently and are easier to understand in role create/edit screens.

## Capabilities

### New Capabilities
- `purchase-permission-normalization`: Normalized permission catalog and enforcement for purchase and purchase-return domains, including grouping consistency and cleanup of unused permissions.

### Modified Capabilities
- None.

## Impact

- Affected areas:
- `app/Config/Permissions.php`
- `Modules/User/Helpers/PermissionHelper.php`
- `Modules/User/Resources/views/roles/*`
- `Modules/Purchase/Routes/web.php`
- `Modules/Purchase/Http/Controllers/*`
- `Modules/Purchase/Resources/views/*`
- `Modules/PurchasesReturn/Routes/web.php`
- `Modules/PurchasesReturn/Http/Controllers/*`
- `Modules/PurchasesReturn/Resources/views/*`
- Potential migration impact on existing roles and permission assignments in `spatie/permission` tables.
- Test impact on purchase, purchase receiving, purchase return, and settlement permission feature tests.
