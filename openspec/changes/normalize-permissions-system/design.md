## Context

Permissions are managed by Spatie Laravel-Permission library, which stores permissions in the `permissions` database table. Currently:

1. **PermissionsTableSeeder** defines 224 permissions in an unstructured array (lines 14-306)
2. **Role views** (`create.blade.php`, `edit.blade.php`) hardcode the same permission groups in two places
3. **Codebase** checks 217 permissions across controllers and blade views via `Gate::*` and `@can`
4. **Legacy naming** is scattered: `brand.*`, `role.*`, `create_quotation*`, `edit_account*`, etc.
5. **Audit findings**: 28 unused permissions, 21 missing from seeder, massive duplication

The permission grouping UI (Adjustments, Penjualan, POS, etc.) is only used for visualization; the underlying system treats all permissions as flat. No code currently normalizes or validates permission structure.

## Goals / Non-Goals

**Goals:**
- Create a single source of truth for permission definitions and groups
- Eliminate duplication across seeder and blade files
- Remove unused permissions (28 not in code)
- Add missing permissions to seeder (21 in code but not seeded)
- Migrate legacy permission names to unified naming conventions (`brand.*`, `role.*`, `create_account*`, etc.)
- Simplify role create/edit views to reference config instead of hardcoding groups
- Maintain backward compatibility during migration (temporary aliasing if needed)
- Make permission management maintainable for future features

**Non-Goals:**
- Rewrite Spatie permission library integration
- Change permission storage (still using database)
- Implement permission hierarchy or inheritance
- Add UI-level permission filtering or customization
- Refactor all 700+ Gate checks—only update ones using legacy names

## Decisions

### Decision 1: Permission Configuration Location & Format
**Choice**: Create `app/Config/Permissions.php` with grouped structure (Option B)

**Rationale**:
- Grouped by feature (`Adjustments`, `Penjualan`, `POS`, etc.) matches current UI organization
- Each group has permission → label mapping for display purposes
- Single PHP file is fast, no database lookup, version-controllable
- Can be lazy-loaded or cached if needed

**Alternative Considered**: Database-driven (custom `permission_groups` table)
- ❌ Overkill for this use case; adds migration complexity
- ❌ Slower (database query every time roles are created/edited)
- ✓ More flexible for tenant-specific customization (future-proofing, but not needed now)

**Structure**:
```php
return [
    'Adjustments' => [
        'adjustments.access' => 'Hak Akses',
        'adjustments.create' => 'Buat',
        'adjustments.edit' => 'Ubah',
        // ... rest of group
    ],
    'Penjualan' => [
        'sales.access' => 'Hak Akses',
        // ...
    ],
    // ... all groups
];
```

### Decision 2: Handling Legacy Permission Names
**Choice**: Direct migration path (update all references in code to new names)

**Rationale**:
- 21 legacy permissions mostly in old modules (Quotation, Account management)
- Small code footprint (maybe 15-20 files affected)
- Cleaner than maintaining dual names or aliases
- One-time migration effort, simpler going forward

**Legacy mappings**:
- `brand.*` → `brands.*` (5 files in Product module)
- `role.*` → `roles.*` (2 files in User module)
- `create_account*` / `edit_account*` → `chartOfAccounts.create` / `chartOfAccounts.edit` (need investigation)
- `create_quotations` → `quotations.create`, `show_quotations` → `quotations.access`, `edit_quotations` → `quotations.edit`
- `send_quotation_mails` → keep as-is (not in seeder currently, will add)
- `create_sale_returns`, `edit_sale_returns` → migrate to `saleReturns.create`, `saleReturns.edit`
- `create_transfers`, `update_transfers` → `stockTransfers.create`, `stockTransfers.edit`
- `sales.search.global` → `sales.access` (global search is subset of access; verify with product owner)

**Alternative Considered**: Temporary aliasing (create both old & new names)
- ✓ Zero-downtime migration
- ❌ Doubles seeder size, confuses future maintainers
- ❌ Eventually still need to remove old names

### Decision 3: Seeder Refactoring
**Choice**: Load permissions from config file; remove unused 28 permissions; normalize legacy names

**Process**:
1. Extract used permissions from `Permissions.php` config
2. Create Permission records for all config permissions
3. Sync Admin role to all permissions (as currently done)
4. Remove permissions NOT in config from database

**Alternative Considered**: Keep separate seeder, just sync with config
- ❌ Doesn't address duplication or unused permissions

### Decision 4: Role View Refactoring
**Choice**: Extract permission groups to a helper function; both create/edit blade files call it

**Options**:
A. Blade component: `<x-permission-groups :groups="$permissionGroups" :checked="$checked" />`
B. Helper function: `PermissionHelper::getGroupsForForm()`
C. Direct config call in view

**Choice**: Option B (Helper function)
- Keeps logic in PHP, not Blade
- Reusable in API responses if needed later
- Testable
- Easy to add validation/filtering

**Function**: `Modules\User\Helpers\PermissionHelper::getGroupsForForm()`
- Returns config groups
- Optionally filters by permissions (for edit, to pre-check existing)

### Decision 5: Unused Permissions (28 to Remove)
**Choice**: Remove completely; these are genuinely unused

**List** (safe to remove):
- POS features: `pos.access`, `pos.cart.clear`, `pos.cart.line.remove`, `pos.cart.line.reduce`, `pos.overrides.price`, `pos.overrides.discount`, `pos.void`, `pos.monitor.access`, `pos.receipts.reprint`, `pos.transactions.view`, `pos.transactions.edit.any`
- Archive/Approved patterns: `adjustments.approved.edit`, `adjustments.archive`, `adjustments.reject`, `adjustments.breakage.approval`, `purchases.approved.edit`, `purchases.archive`, `purchaseReturns.approved.edit`, `purchaseReturns.archive`, `saleReturns.approved.edit`, `saleReturns.archive`, `stockTransfers.approved.edit`, `stockTransfers.archive`
- Purchase settlement: `purchaseReturnSettlements.*` (not in used list)
- Sale settlement archive: `saleReturnSettlements.archive`

**Verification**: Run codebase grep for each before removal to confirm truly unused

## Risks / Trade-offs

**Risk 1: Legacy permission name migration misses some references**
- **Mitigation**: Run comprehensive grep for all old names before/after. Test role creation/editing thoroughly.

**Risk 2: Some legacy names map to multiple new names (ambiguous)**
- **Example**: `create_account*` unclear if it's `chartOfAccounts.create` or user account creation
- **Mitigation**: Investigate code context; add comments in commit message explaining mapping decisions. Defer unclear ones to follow-up if critical.

**Risk 3: Database has roles assigned old permissions; migration fails**
- **Mitigation**: In seeder, create new permissions first; sync roles to new ones; then delete old. Verify all roles have permissions before deletion.

**Risk 4: Removing unused permissions breaks custom/plugin code**
- **Mitigation**: Document breaking change; provide migration guide. Unused permissions are truly unused in core, but could theoretically exist elsewhere.

**Risk 5: Permission groups in config get out of sync with actual usage**
- **Mitigation**: Add automation/CI check: verify all permissions in config are used in code (Gate checks). Add comment in config linking to seeder.

## Migration Plan

**Phase 1: Config Creation & Seeder Refactoring** (safe, reversible)
1. Create `app/Config/Permissions.php` with all 196 active permissions grouped
2. Update `PermissionsTableSeeder` to use config
3. Run seeder; verify all permissions exist in database
4. Verify Admin role has all permissions
5. Smoke test: load `/roles` page, check role creation still works

**Phase 2: Legacy Name Migration** (requires code updates)
1. Update `Modules/Product/Resources/views/brands/*` to use `brands.*` instead of `brand.*`
2. Update `Modules/Product/Http/Controllers/BrandController.php` to use new names
3. Update `Modules/User/Resources/views/roles/*` to use `roles.*`
4. Update `Modules/User/Http/Controllers/RolesController.php` to use new names
5. Update Quotation controller/views to use standardized names
6. Search for remaining legacy names; update or verify as intentional
7. Re-run seeder to confirm permissions created
8. Test role create/edit flow end-to-end

**Phase 3: View Simplification** (UI improvement)
1. Create `Modules/User/Helpers/PermissionHelper.php` with `getGroupsForForm()` method
2. Refactor `create.blade.php` to call helper instead of hardcoding array
3. Refactor `edit.blade.php` to call helper
4. Remove hardcoded `$permissionGroups` arrays from both views
5. Test both views render correctly, checkboxes work, form submission works

**Phase 4: Cleanup** (final polish)
1. Verify all 28 unused permissions are truly unused (grep + manual spot check)
2. Run seeder to remove unused permissions from database
3. Smoke tests on all role-related pages
4. Update any documentation

**Rollback Plan**:
- If Phase 1 breaks: revert seeder changes, rerun old seeder
- If Phase 2 breaks: revert blade/controller changes; old permission names still in database so features still work
- If Phase 3 breaks: revert blade changes; logic doesn't change, just view structure
- If Phase 4 breaks: restore old seeder; recreate unused permissions via seeder

## Open Questions

1. **`create_account*` and `edit_account*` permissions**: Are these for Chart of Accounts (chartOfAccounts) or user account creation (users)? Need code search to clarify.
2. **`sales.search.global`**: Is this a distinct permission or part of `sales.access`? Should we keep or consolidate?
3. **Should we add a new role with sensible defaults** (e.g., "Cashier", "Supervisor") seeded with common permissions? (Out of scope for this change but worth thinking about)
4. **Timeline**: Should this be one PR or split into multiple? (Recommend: 2 PRs - Phase 1+2 together, Phase 3+4 together for safer rollback)
