## 1. Baseline Audit and Mapping

- [x] 1.1 Build an explicit inventory of purchase and purchase-return permission usage across config, routes, controllers, Livewire, and Blade views.
- [x] 1.2 Define the legacy-to-canonical permission mapping table (including keys to rename, merge, retain, and remove).
- [x] 1.3 Decide and document final resolution for ambiguous pairs (`view` vs `show`, print vs show, lifecycle receive vs settlement receive).

## 2. Canonical Catalog and Role Group Normalization

- [x] 2.1 Update purchase and purchase-return permission definitions in `app/Config/Permissions.php` to the canonical taxonomy.
- [x] 2.2 Normalize purchase-related group labels and ordering for role create/edit screens via configuration-driven grouping.
- [x] 2.3 Validate that role management surfaces only canonical, non-duplicate purchase permissions.

## 3. Authorization Enforcement Alignment

- [x] 3.1 Align purchase routes/controllers/Livewire/Blade checks to canonical keys and remove undefined gate references.
- [x] 3.2 Align purchase-return routes/controllers/Livewire/Blade checks to canonical keys and remove undefined gate references.
- [x] 3.3 Remove dead or unreachable permission-guarded UI/actions that no longer map to active routes/workflows.

## 4. Migration and Cleanup

- [x] 4.1 Implement role-permission remap migration so existing roles keep equivalent access after key normalization.
- [x] 4.2 Run permission synchronization to prune deprecated purchase-domain keys after remap safety is in place.
- [x] 4.3 Prepare rollback notes for restoring legacy keys/assignments if access regression is detected.

## 5. Verification and Regression Coverage

- [x] 5.1 Add/update feature tests that assert protected purchase and purchase-return endpoints require defined canonical permissions.
- [x] 5.2 Add/update UI-level authorization tests for purchase and purchase-return action visibility parity.
- [x] 5.3 Execute purchase and purchase-return permission regression suite and confirm no active path depends on removed keys.
