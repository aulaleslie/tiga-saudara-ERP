## 1. Shared uniqueness validation rule

- [x] 1.1 Add a reusable case-insensitive, trimmed uniqueness `Rule` (or equivalent helper) for checking a `customers` column value against existing records, accepting the column name, the candidate value, and an optional excluded id (for updates).
- [x] 1.2 Ensure the rule treats null/empty/whitespace-only values as "not a duplicate" (never fires when the incoming value is blank).
- [x] 1.3 Ensure the rule query is global (no `setting_id` scoping).

## 2. Wire validation into write paths

- [x] 2.1 `Modules/People/Http/Controllers/CustomersController@store` — add uniqueness validation for `customer_name` and `contact_name`, following the existing inline-closure style used for `customer_phone`/`customer_email`/`identity_number`/`npwp` or the new shared rule from Section 1.
- [x] 2.2 `Modules/People/Http/Controllers/CustomersController@update` — same as 2.1, excluding the current customer's id.
- [x] 2.3 `app/Livewire/Modules/People/Modals/CustomerQuickAddModal.php` — add uniqueness validation for `customer_name` and `contact_name` in `save()`.
- [x] 2.4 `app/Livewire/Customer/CreateModal.php` — add uniqueness validation for `customer_name` and `contact_name` in `save()`.
- [x] 2.5 `Modules/Pos/Http/Controllers/PosSellController@customerStore` — add uniqueness validation for `customer_name` (contact_name stays forced null, no check needed there for this path).
- [x] 2.6 Ensure each path surfaces a clear, user-facing validation error message (Bahasa Indonesia, matching existing message style in `CustomersController`, e.g. "Nama pelanggan sudah digunakan.").

## 3. Spec correction

- [x] 3.1 Confirm the `customer-creation-field-consistency` MODIFIED delta in this change accurately reflects that POS leaves `contact_name` null (already drafted in `specs/customer-creation-field-consistency/spec.md`).

## 4. Focused verification

- [x] 4.1 Add/extend a feature test for `CustomersController@store` covering: duplicate `customer_name` rejected (case/whitespace variants), duplicate `contact_name` rejected, distinct names accepted, cross-setting duplicate rejected.
- [x] 4.2 Add/extend a feature test for `CustomersController@update` covering: update rejected on collision with another customer, update allowed when name unchanged (no false positive against self).
- [x] 4.3 Add/extend a test for `CustomerQuickAddModal` (Livewire component test) covering duplicate `customer_name` rejection.
- [x] 4.4 Add/extend a test for `Customer\CreateModal` (Livewire component test) covering duplicate `customer_name` rejection.
- [x] 4.5 Add/extend a test for `PosSellController@customerStore` covering duplicate `customer_name` rejection.
- [x] 4.6 Run only the customer-related test files touched/added above (e.g. `php artisan test --filter=Customer`), not the full suite, to verify the change in isolation.
