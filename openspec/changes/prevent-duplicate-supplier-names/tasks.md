## 1. Shared uniqueness validation rule

- [ ] 1.1 Generalize `Modules/People/Rules/UniqueCustomerField.php` into a table-agnostic uniqueness rule (or extract a shared base class) usable against both `customers` and `suppliers`, preserving its existing case-insensitive/trimmed/excludable-id behavior exactly for `customers` calls.
- [ ] 1.2 Confirm the generalized rule treats null/empty/whitespace-only values as "not a duplicate" for the `suppliers.contact_name` case, same as it already does for customers.
- [ ] 1.3 Confirm the rule's query against `suppliers` is global (no `setting_id` scoping).

## 2. Wire validation into supplier write paths

- [ ] 2.1 `Modules/People/Http/Controllers/SuppliersController@store` — replace the existing `setting_id`-scoped exact-match `supplier_name` check with the global, case-insensitive/trimmed rule; add a `contact_name` uniqueness check (currently missing).
- [ ] 2.2 `Modules/People/Http/Controllers/SuppliersController@update` — same as 2.1, excluding the current supplier's id.
- [ ] 2.3 `app/Livewire/Modules/People/Modals/SupplierQuickAddModal.php` — add `supplier_name` and `contact_name` uniqueness validation in `save()`.
- [ ] 2.4 `routes/api.php` `POST /api/suppliers` closure — add `supplier_name` and `contact_name` uniqueness validation.
- [ ] 2.5 Ensure each path surfaces a clear, user-facing validation error message (Bahasa Indonesia, matching existing message style, e.g. "Nama pemasok sudah digunakan.", "Nama kontak sudah digunakan.").

## 3. Global supplier matching and search

- [ ] 3.1 `Modules/Purchase/Services/PurchaseImportService::findOrCreateSupplier` — drop the `setting_id` condition from the match query (keep it case-insensitive/trimmed); keep writing `setting_id` on `Supplier::create()` as provenance only.
- [ ] 3.2 `Modules/Expense/Services/ExpenseImportService::findOrCreateSupplier` — same change as 3.1.
- [ ] 3.3 `Modules/Expense/Services/ExpenseImportService.php` — change the hardcoded `contact_name` placeholder from `'Imported Supplier'` to `null` for created suppliers with no import-provided contact name.
- [ ] 3.4 `Modules/People/Livewire/SupplierSearchDropdown.php` — drop the `setting_id` filter from `fetchSuppliers()` and `resolveLabel()` so search/browse results are global, consistent with selection-by-id already being global.

## 4. Focused verification

- [ ] 4.1 Add/extend a feature test for `SuppliersController@store` covering: duplicate `supplier_name` rejected (case/whitespace variants, including across two different settings), duplicate `contact_name` rejected, distinct names accepted.
- [ ] 4.2 Add/extend a feature test for `SuppliersController@update` covering: update rejected on collision with another supplier, update allowed when name unchanged (no false positive against self).
- [ ] 4.3 Add/extend a test for `SupplierQuickAddModal` (Livewire component test) covering duplicate `supplier_name` rejection.
- [ ] 4.4 Add/extend a feature test for `POST /api/suppliers` covering duplicate `supplier_name` rejection.
- [ ] 4.5 Add/extend a test for `PurchaseImportService::findOrCreateSupplier` and `ExpenseImportService::findOrCreateSupplier` covering: an import in one setting matches an existing supplier created under a different setting (no duplicate created); a second import with no contact name does not collide on the `contact_name` uniqueness check (verifying the `null` placeholder fix).
- [ ] 4.6 Add/extend a test confirming `SupplierSearchDropdown` returns suppliers regardless of the active setting.
- [ ] 4.7 Re-run the existing customer uniqueness tests (from `prevent-duplicate-customer-names`) to confirm the shared-rule generalization introduced no regression in `customers` behavior.
- [ ] 4.8 Run only the supplier-related and shared-rule test files touched/added above (e.g. `php artisan test --filter=Supplier` plus the customer uniqueness filter from 4.7), not the full suite, to verify the change in isolation.
