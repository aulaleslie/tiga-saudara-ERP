## Why

Supplier creation is scattered across five write paths (admin CRUD, a Livewire quick-add modal, an Alpine-driven API endpoint, and two import services) with inconsistent and incomplete duplicate protection: two paths reject exact-match `supplier_name` duplicates but only within the creating setting, the remaining paths have no duplicate check at all, and none check `contact_name`. Supplier records are also inconsistently scoped — selection-by-id is already effectively global (`Supplier::find($id)` has no `setting_id` filter), but search/browse (`SupplierSearchDropdown`) and both import services' matching logic still filter by `setting_id`, meaning the same real-world supplier can silently end up as separate records per setting. This change establishes suppliers as a single global identity — mirroring the customer model already adopted (`global-customer-identity`) — and adds consistent, global, case-insensitive duplicate prevention on `supplier_name` and `contact_name` across every creation path.

## What Changes

- Establish `setting_id` on `suppliers` as provenance-only: it records which setting originally created a supplier record but MUST NOT be used to filter, scope, or restrict supplier search, matching, selection, or duplicate detection.
- Add case-insensitive, trimmed, global uniqueness validation for `supplier_name` on every create/update entry point, replacing the two existing `setting_id`-scoped exact-match checks in the admin controller.
- Add the same case-insensitive, trimmed, global uniqueness validation for `contact_name` (non-empty values only) — a check that does not exist anywhere today.
- Apply the validation consistently across all five supplier write paths:
  - `Modules/People/Http/Controllers/SuppliersController@store`
  - `Modules/People/Http/Controllers/SuppliersController@update`
  - `app/Livewire/Modules/People/Modals/SupplierQuickAddModal.php`
  - `POST /api/suppliers` (routes/api.php closure, used by the Alpine quick-add modal)
  - `Modules/Purchase/Services/PurchaseImportService::findOrCreateSupplier`
  - `Modules/Expense/Services/ExpenseImportService::findOrCreateSupplier`
- De-scope supplier search/matching from `setting_id`:
  - `Modules/People/Livewire/SupplierSearchDropdown::fetchSuppliers()` / `resolveLabel()` — drop the `setting_id` filter so search/browse surfaces every supplier, consistent with selection-by-id already being global.
  - `PurchaseImportService::findOrCreateSupplier` / `ExpenseImportService::findOrCreateSupplier` — drop `setting_id` from the match query so import matching finds an existing supplier regardless of which setting created it, instead of creating a duplicate per setting.
- **BREAKING** (data-matching behavior): a purchase or expense import that previously created a new supplier per setting for the same supplier name will now match the single existing global record instead. No historical data is modified or backfilled — this only changes matching behavior for future import runs.
- Fix `ExpenseImportService`'s hardcoded `contact_name = 'Imported Supplier'` placeholder (already shared by 14 existing supplier records) to store `null` instead, so it does not collide with the new `contact_name` uniqueness check on every subsequent import.
- No change to `Purchase.setting_id` / `Expense.setting_id` — those remain transaction-ownership fields, entirely independent of the supplier record's own (now purely historical) `setting_id`. No existing Purchase or Expense records are modified.
- No database-level unique constraint — validation stays application-level only, consistent with the equivalent customer change (`prevent-duplicate-customer-names`).

## Capabilities

### New Capabilities
- `supplier-name-uniqueness`: Case-insensitive, trimmed, global uniqueness validation for `supplier_name` and `contact_name` across every supplier create/update and import-matching entry point.
- `global-supplier-identity`: Suppliers are a single global entity; `setting_id` is provenance-only and MUST NOT scope search, matching, selection, or duplicate detection.

### Modified Capabilities
(none — no existing spec currently documents supplier scoping or uniqueness behavior)

## Impact

- **Code**: `Modules/People/Http/Controllers/SuppliersController.php` (store/update validation), `app/Livewire/Modules/People/Modals/SupplierQuickAddModal.php`, `routes/api.php` (`POST /api/suppliers` closure), `Modules/Purchase/Services/PurchaseImportService.php`, `Modules/Expense/Services/ExpenseImportService.php`, `Modules/People/Livewire/SupplierSearchDropdown.php`. No model schema/migration changes.
- **Specs**: New `supplier-name-uniqueness` and `global-supplier-identity` specs.
- **Data**: No migration or backfill. Existing supplier records (212 across 7 settings; verified zero existing `supplier_name` collisions, both within-setting and globally) are left as-is. One data-adjacent fix: the `ExpenseImportService` placeholder `contact_name` value changes from a shared literal string to `null` for future imports only.
- **Behavior**: Future purchase/expense imports will match an existing global supplier record by name instead of creating a new one scoped to the importing setting. Existing Purchase/Expense records and their supplier attributions are unaffected.
- **Tests**: Focused feature-test coverage per write path (not a full regression suite run) — see tasks.md.
