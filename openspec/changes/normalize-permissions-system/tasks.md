## 1. Create Centralized Permission Configuration

- [x] 1.1 Create `app/Config/Permissions.php` with grouped permission structure (Option B format)
- [x] 1.2 Organize all 196 active permissions by feature group (Adjustments, Penjualan, Retur Penjualan, Pembayaran, POS, etc.)
- [x] 1.3 Include human-readable labels for each permission in Indonesian
- [x] 1.4 Ensure permission groups exactly match the UI organization in role views
- [x] 1.5 Add documentation/comments explaining the structure and how to add new permissions

## 2. Investigate Legacy Permission Ambiguities

- [ ] 2.1 Search codebase for `create_account` and `edit_account` usage to determine correct mapping (chartOfAccounts or users?)
- [ ] 2.2 Verify `sales.search.global` is distinct from `globalSalesSearch.access` or consolidate mapping
- [ ] 2.3 Document all legacy→new permission mappings in a reference document
- [ ] 2.4 Get sign-off on ambiguous mappings (or make documented decision)

## 3. Create Permission Helper Function

- [x] 3.1 Create `Modules/User/Helpers/PermissionHelper.php` with `getGroupsForForm()` method.php` with `getGroupsForForm()` method
- [x] 3.2 Function returns permission groups from configuration, optionally filtered for pre-checking
- [ ] 3.3 Add tests for helper function (groups returned, filtering works)
- [ ] 3.4 Document helper function usage

## 4. Refactor PermissionsTableSeeder

- [x] 4.1 Update `PermissionsTableSeeder.php` to load permissions from `Permissions.php` config.php` to load permissions from `Permissions.php` config
- [x] 4.2 Implement logic to create missing permissions in database
- [x] 4.3 Implement logic to sync Admin role to all config permissions
- [x] 4.4 Implement logic to delete permissions not in config (and their role assignments)
- [x] 4.5 Add transaction wrapper for atomicity
- [x] 4.6 Add informative console output (permissions created, deleted, synced, etc.)
- [x] 4.7 Test seeder: run and verify database state matches config

## 5. Migrate Legacy Permission Names - Brands

- [x] 5.1 Update `Modules/Product/Resources/views/brands/index.blade.php` to use `brands.create` instead of `brand.create`.blade.php` to use `brands.create` instead of `brand.create`
- [x] 5.2 Update `Modules/Product/Resources/views/brands/partials/actions.blade.php` to use new permission names.php` to use new permission names
- [ ] 5.3 Update `Modules/Product/Http/Controllers/BrandController.php` (Gate checks) to use new names
- [ ] 5.4 Search for any other brand-related permission checks and update
- [ ] 5.5 Verify brand creation/edit/delete flows work with new permission names

## 6. Migrate Legacy Permission Names - Roles

- [x] 6.1 Update `Modules/User/Resources/views/roles/partials/actions.blade.php` to use `roles.edit` and `roles.delete` instead of `role.edit`, `role.delete`.blade.php` to use `roles.edit` and `roles.delete` instead of `role.edit`, `role.delete`
- [ ] 6.2 Update `Modules/User/Http/Controllers/RolesController.php` (Gate checks) if using legacy names
- [ ] 6.3 Search for any other role-related permission checks using singular form and update
- [ ] 6.4 Verify role creation/edit/delete flows work with new names

## 7. Migrate Legacy Permission Names - Quotations

- [ ] 7.1 Update `Modules/Quotation/Http/Controllers/QuotationController.php` to use `quotations.access`, `quotations.create`, `quotations.edit`
- [ ] 7.2 Update `Modules/Quotation/Http/Requests/StoreQuotationRequest.php` and `UpdateQuotationRequest.php`
- [ ] 7.3 Search quotation views for @can directives and update to new names
- [ ] 7.4 Handle `send_quotation_mails` permission (ensure it's added to config if used)
- [ ] 7.5 Handle `show_quotations` properly (may consolidate with `quotations.access`)
- [ ] 7.6 Test quotation creation, editing, viewing, and mailing flows

## 8. Migrate Legacy Permission Names - Accounts and Other Legacy Names

- [ ] 8.1 Locate all `create_account` / `edit_account` usage and determine correct mapping
- [ ] 8.2 Update code to use correct mapped permission names
- [ ] 8.3 Handle `create_sale_returns` → `saleReturns.create` migration
- [ ] 8.4 Handle `edit_sale_returns` → `saleReturns.edit` migration
- [ ] 8.5 Handle `create_transfers` → `stockTransfers.create` migration
- [ ] 8.6 Handle `update_transfers` → `stockTransfers.edit` migration
- [ ] 8.7 Handle `sales.search.global` (verify, consolidate, or keep as-is)
- [ ] 8.8 Search for any remaining legacy pattern (singular permission names, underscore-based names)

## 9. Simplify Role Create View

- [x] 9.1 Remove hardcoded `$permissionGroups` array from `roles/create.blade.php`
- [x] 9.2 Call `PermissionHelper::getGroupsForForm()` to get groups instead::getGroupsForForm()` to get groups instead
- [ ] 9.3 Simplify blade code to use returned groups structure
- [ ] 9.4 Verify create form still displays correctly, all groups present
- [ ] 9.5 Verify checkboxes work, group toggles work, global toggle works
- [ ] 9.6 Test form submission (validation, permission assignment)

## 10. Simplify Role Edit View

- [x] 10.1 Remove hardcoded `$permissionGroups` array from `roles/edit.blade.php`
- [x] 10.2 Call `PermissionHelper::getGroupsForForm()` to get groups::getGroupsForForm()` to get groups
- [ ] 10.3 Update logic to pre-check existing role permissions without duplication
- [ ] 10.4 Verify edit form displays correctly with pre-checked permissions
- [ ] 10.5 Verify checkboxes work, pre-checking doesn't break group toggles
- [ ] 10.6 Test form submission (permission sync, additions, removals)

## 11. Update RolesController Validation (if needed)

- [ ] 11.1 Review `RolesController` store/update methods for permission validation
- [ ] 11.2 If validation hardcodes permission names, consider making it reference config
- [ ] 11.3 Ensure validation accepts all valid permission names from config
- [ ] 11.4 Add any additional validation if permissions need to be checked against config

## 12. Verify and Remove Unused Permissions

- [ ] 12.1 Create comprehensive list of 28 unused permissions to remove
- [ ] 12.2 For each unused permission, run grep to confirm truly not used in code
- [ ] 12.3 Ensure all unused permissions are removed from `Permissions.php` config
- [ ] 12.4 Run seeder to delete unused permissions from database
- [ ] 12.5 Verify Admin role and other roles do not have gaps or errors

## 13. Comprehensive Testing

- [ ] 13.1 Run seeder; verify all 196 permissions created, 28 unused removed
- [ ] 13.2 Test role creation flow end-to-end (form renders, permissions display, form submits, role created)
- [ ] 13.3 Test role editing flow end-to-end (form renders with pre-checked perms, permissions update correctly)
- [ ] 13.4 Test role deletion
- [ ] 13.5 Test Admin role has all 196 permissions
- [ ] 13.6 Test Manager and Karyawan roles still work with their seeded permissions
- [ ] 13.7 Test permission checks work in controllers (brands, roles, quotations, etc.)
- [ ] 13.8 Test permission checks work in views (brands, roles, quotations, etc.)
- [ ] 13.9 Search for any remaining legacy permission names; confirm none in active code
- [ ] 13.10 Smoke test all major flows (create sales/purchases, manage products, manage roles)

## 14. Documentation and Cleanup

- [ ] 14.1 Update any developer documentation about permissions (if exists)
- [ ] 14.2 Add docblock to `Permissions.php` explaining structure and how to add permissions
- [ ] 14.3 Add migration guide documenting legacy→new permission mappings
- [ ] 14.4 Verify no hardcoded `$permissionGroups` arrays remain in codebase
- [ ] 14.5 Clean up any temporary comments or debug code
- [ ] 14.6 Create a summary of changes for release notes (if applicable)

## 15. Final Verification

- [ ] 15.1 Run full test suite (unit tests, feature tests)
- [ ] 15.2 Verify no broken permission references in code (grep for old names)
- [ ] 15.3 Manually test all role/permission flows in browser
- [ ] 15.4 Verify database schema is clean (no orphaned roles/permissions)
- [ ] 15.5 Get final sign-off before merge
