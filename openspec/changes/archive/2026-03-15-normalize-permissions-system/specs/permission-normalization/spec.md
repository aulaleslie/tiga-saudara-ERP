## ADDED Requirements

### Requirement: Unified permission naming conventions
The system SHALL use consistent, modern permission naming conventions across the entire codebase. All legacy permission names (singular forms, underscore-based naming) SHALL be migrated to a standardized pattern using dot notation and plural nouns where applicable.

#### Scenario: Legacy brand permissions are migrated
- **WHEN** code checks permissions for brand management operations
- **THEN** it uses `brands.create`, `brands.edit`, `brands.delete`, `brands.view` instead of legacy `brand.create`, `brand.edit`, `brand.delete`
- **AND** all views and controllers referencing brand permissions use the new names
- **AND** the database contains permissions with the new names

#### Scenario: Legacy role permissions are migrated
- **WHEN** code checks permissions for role management operations
- **THEN** it uses `roles.create`, `roles.edit`, `roles.delete` instead of legacy `role.create`, `role.edit`, `role.delete`
- **AND** the roles/edit and roles/delete actions in RolesController check against the new names

#### Scenario: Quotation permissions follow unified naming
- **WHEN** code checks permissions for quotation operations
- **THEN** it uses `quotations.access`, `quotations.create`, `quotations.edit` instead of legacy `show_quotations`, `create_quotations`, `edit_quotations`
- **AND** the mail-sending permission is named `quotations.mail.send` (or similar dot-notation style)

#### Scenario: Chart of Accounts permissions are clarified
- **WHEN** code checks permissions for account/chart of accounts operations
- **THEN** it uses `chartOfAccounts.create` and `chartOfAccounts.edit` for chart management
- **AND** legacy names like `create_account` and `edit_account` are replaced with the standardized form

#### Scenario: Sale return and stock transfer operations use unified naming
- **WHEN** code checks permissions for sale return or stock transfer creation/editing
- **THEN** it uses `saleReturns.create`, `saleReturns.edit`, `stockTransfers.create`, `stockTransfers.edit`
- **AND** legacy names like `create_sale_returns`, `edit_sale_returns`, `create_transfers`, `update_transfers` are replaced

#### Scenario: Global search permission is standardized
- **WHEN** code checks for global sales search access
- **THEN** it uses `globalSalesSearch.access` (already in standard form)
- **AND** if `sales.search.global` is encountered, it is either consolidated or renamed

### Requirement: All permission checks in code use the new naming
The codebase SHALL use only the new, unified permission names when checking authorization. No legacy permission names SHALL remain in active code paths.

#### Scenario: Controller authorization checks use new names
- **WHEN** a controller method calls `Gate::denies()` or `Gate::allows()`
- **THEN** it references a permission name that exists in the centralized configuration
- **AND** no controller references legacy permission names like `brand.*`, `role.*`, `create_*`, `edit_*`

#### Scenario: View authorization checks use new names
- **WHEN** a blade view file uses `@can()`, `@cannot()`, or `@canany()`
- **THEN** it references a permission name that exists in the centralized configuration
- **AND** no view references legacy permission names

#### Scenario: Request authorization checks use new names
- **WHEN** a Form Request's `authorize()` method checks permissions
- **THEN** it uses the new unified naming convention
- **AND** legacy names are not present

### Requirement: Permission migration is verified and testable
The migration from legacy to unified permission names SHALL be completable and verifiable through automated checks.

#### Scenario: All legacy permission names are identified and catalogued
- **WHEN** the migration process begins
- **THEN** a comprehensive list of all legacy permission names is created
- **AND** each legacy name is mapped to its replacement (or identified as unused/removed)
- **AND** the mapping is documented in the migration guide

#### Scenario: Code grep confirms all legacy names are removed
- **WHEN** the migration is complete
- **THEN** a search of the codebase for legacy permission names (e.g., `brand\.`, `role\.`, `create_*`, `edit_*`) returns no results
- **AND** except in comments or documentation explaining the migration

#### Scenario: New roles created after migration use only new permission names
- **WHEN** a user creates a new role through the role creation form
- **THEN** only permissions with the new unified names are available to assign
- **AND** the form displays permissions organized by the feature groups in the configuration
