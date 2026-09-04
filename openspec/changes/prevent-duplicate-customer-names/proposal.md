## Why

Customer creation has no uniqueness check on `customer_name` or `contact_name` in any of its five write paths. Nothing stops two customers from being saved with the same name (or the same name with different casing/whitespace), which already causes confusion in customer selection (POS, Sales) and reporting. Production data already contains 42 case-insensitive duplicate `customer_name` groups (e.g. three separate customers all named "arif"), so this change adds forward-looking validation without attempting to retroactively resolve existing collisions.

## What Changes

- Add case-insensitive, trimmed uniqueness validation for `customer_name` on customer create and update, applied globally (not scoped to `setting_id`), consistent with the existing global-customer-identity model where customers are shared/selectable across settings.
- Add the same case-insensitive, trimmed uniqueness validation for `contact_name` (currently zero collisions in production data, so no pre-existing conflicts block this).
- Apply the new validation consistently across all five customer write paths:
  - `Modules/People/Http/Controllers/CustomersController@store`
  - `Modules/People/Http/Controllers/CustomersController@update`
  - `app/Livewire/Modules/People/Modals/CustomerQuickAddModal.php`
  - `app/Livewire/Customer/CreateModal.php`
  - `Modules/Pos/Http/Controllers/PosSellController@customerStore`
- Validation is application-level only (Laravel `Rule::unique` with a case-insensitive comparison, or an equivalent query check) — no new database-level unique constraint, since 42 existing `customer_name` collisions would block a hard DB constraint from being added. This is a deliberate scope boundary, not an oversight; DB-level enforcement is a candidate follow-up once existing duplicates are resolved.
- No change to `contact_name` being optional/nullable in POS (`PosSellController@customerStore` continues to store `contact_name = null`); this run does not attempt to reconcile the older `customer-creation-field-consistency` spec's expectation that POS should mirror `customer_name` into `contact_name`. That spec will be corrected to match current, intended behavior as part of this change's spec updates (see Modified Capabilities) since it currently documents behavior that contradicts the newer `global-customer-identity` spec and current code.
- No change to existing display behavior (`Customer::display_name`, `Customer::canonical_name`, the customers DataTable's separate "Nama Pelanggan"/"Kontak" columns) — display already treats `customer_name` as canonical and `contact_name` as supplemental, and that is already consistent.

## Capabilities

### New Capabilities
- `customer-name-uniqueness`: Case-insensitive, trimmed, global uniqueness validation for `customer_name` and `contact_name` across all customer create/update entry points.

### Modified Capabilities
- `customer-creation-field-consistency`: Correct the existing requirement that POS-created customers must populate `contact_name` with the customer name. Current and intended behavior (confirmed in this change) is that POS leaves `contact_name` null, consistent with `global-customer-identity`'s treatment of `contact_name` as optional supplemental information.

## Impact

- **Code**: `Modules/People/Http/Controllers/CustomersController.php` (store/update validation), `app/Livewire/Modules/People/Modals/CustomerQuickAddModal.php`, `app/Livewire/Customer/CreateModal.php`, `Modules/Pos/Http/Controllers/PosSellController.php` (customerStore validation). No model schema/migration changes.
- **Specs**: New `customer-name-uniqueness` spec; corrected `customer-creation-field-consistency` spec.
- **Data**: No migration or backfill. The 42 existing `customer_name` collisions in production are left as-is; a data-cleanup/merge effort is out of scope and called out as a follow-up, not silently dropped.
- **Tests**: Focused feature-test coverage per write path (not a full regression suite run) — see tasks.md.
