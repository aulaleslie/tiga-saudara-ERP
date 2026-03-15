## Why

Permissions are currently scattered across three locations: `PermissionsTableSeeder.php` (224 definitions), and two hardcoded blade views (`create.blade.php` and `edit.blade.php`). This creates duplication, inconsistency, and makes maintenance difficult. Additionally, 28 permissions are defined but never used in code, and 21 permissions are used in code but not defined in the seeder. The role UI also inconsistently uses legacy permission naming (e.g., `brand.*` vs `brands.*`, `role.*` vs `roles.*`), adding confusion.

By centralizing permissions in a single configuration file with human-readable labels grouped by feature, we gain a single source of truth, eliminate duplication, improve maintainability, and can cleanly migrate legacy names.

## What Changes

- **Create centralized permission configuration** (`app/Config/Permissions.php`) with grouped, labeled permissions—the single source of truth
- **Normalize seeder** to use the config file; remove 28 unused permissions; standardize legacy names to modern naming conventions
- **Extract permission groups from blade views** and reference the config instead of hardcoding
- **Update controllers** (RolesController) to use the config for validation
- **Migrate legacy permission names** across the codebase (`brand.*` → `brands.*`, `role.*` → `roles.*`, `create_account*` → `chartOfAccounts.*`, etc.)
- **Reduce permission duplication** from 224 down to 196 active, used permissions

## Capabilities

### New Capabilities
- `permission-config`: Centralized permission definitions with grouped structure and human labels
- `permission-normalization`: Unified permission naming conventions across the entire system

### Modified Capabilities
- `role-management`: Simplified role create/edit flow using centralized permission definitions instead of duplicated hardcoded lists

## Impact

**Code changes:**
- `PermissionsTableSeeder.php`: Refactored to use config
- `RolesController.php`: Updated validation logic
- `Modules/User/Resources/views/roles/create.blade.php` & `edit.blade.php`: Simplified to use config groups
- `Modules/Product/Resources/views/brands/*`: Updated permission names
- `Modules/User/Resources/views/roles/*`: Updated permission names
- `Modules/Quotation/Http/Controllers/QuotationController.php` and related files: Updated to use standardized permission names

**Breaking changes:**
- **BREAKING**: Legacy permission names will be replaced (`brand.*` → `brands.*`, `role.*` → `roles.*`, etc.). Old names must be migrated.
- **BREAKING**: 28 unused permissions removed from system—any custom code relying on them must be updated.

**System impact:**
- All role assignments will work with the new unified naming convention
- Migration path: new permissions created alongside old ones temporarily, then old removed after verification
