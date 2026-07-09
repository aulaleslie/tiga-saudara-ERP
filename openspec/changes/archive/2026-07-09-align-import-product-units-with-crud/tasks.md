## 1. Characterization Tests

- [x] 1.1 Add focused Sales import coverage proving a newly created product receives `base_unit_id`, `unit_id`, and `product_unit` from the imported unit.
- [x] 1.2 Add focused Purchase import coverage proving a newly created product receives `base_unit_id`, `unit_id`, and `product_unit` from the imported unit.
- [x] 1.3 Add Product edit/update coverage proving an import-created stock-managed product can update editable price fields without changing its locked unit.
- [x] 1.4 Add UI/component coverage proving the Unit Utama quick-add button is unavailable when the unit dropdown is disabled and available when enabled.

## 2. Import Unit Alignment

- [x] 2.1 Update Sales import product creation to set `base_unit_id` to the resolved imported unit ID while preserving `unit_id`.
- [x] 2.2 Update Sales import product creation to populate `product_unit` from the resolved unit short name or name.
- [x] 2.3 Update Purchase import product creation to set `base_unit_id` to the resolved imported unit ID while preserving `unit_id`.
- [x] 2.4 Update Purchase import product creation to populate `product_unit` from the resolved unit short name or name.

## 3. Existing Data Repair

- [x] 3.1 Add an idempotent migration or repair command for stock-managed products with missing `base_unit_id` and valid `unit_id`.
- [x] 3.2 Ensure the repair sets `base_unit_id = unit_id`, preserves `unit_id`, and fills blank `product_unit` from unit metadata.
- [x] 3.3 Ensure products without a valid `unit_id` are not assigned a guessed/default unit and remain available for manual follow-up.
- [x] 3.4 Add repair coverage for successful repair, invalid-unit no-op behavior, and idempotency.

## 4. Disabled Quick-Add Controls

- [x] 4.1 Update the unit search dropdown so quick-create controls are hidden or disabled when the dropdown is disabled.
- [x] 4.2 Review shared searchable dropdowns with disabled/read-only support and align adjacent clear/create actions where applicable.
- [x] 4.3 Verify Product edit locked unit rendering preserves the current unit value and disabled-field form submission behavior.

## 5. Verification

- [x] 5.1 Run focused tests for Sales import product unit alignment.
- [x] 5.2 Run focused tests for Purchase import product unit alignment.
- [x] 5.3 Run focused tests for Product edit/update and disabled quick-add behavior.
- [x] 5.4 Run an appropriate broader PHP verification pass, preferring `php artisan test` with focused filters or `composer test:fresh-sqlite` if scope warrants it.
