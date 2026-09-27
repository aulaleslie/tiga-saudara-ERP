## 1. Regression Coverage for the Canonical Contract

- [x] 1.1 Add focused regression coverage for an import-shaped customer (`customer_name` populated, `contact_name` null/empty) appearing with a nonblank canonical name in the customer DataTable, shared Sales dropdown, legacy loader, and POS customer search.
- [x] 1.2 Add model/display regression cases for whitespace-safe fallback, equal `customer_name`/`contact_name` deduplication, and distinct supplemental contact context.
- [x] 1.3 Replace old POS and Livewire quick-add assertions that require duplicated contact data with assertions that `customer_name` is canonical and omitted `contact_name` remains null/unset.

## 2. Customer Read and Presentation Normalization

- [x] 2.1 Update the Customer model’s centralized name resolution so `customer_name` is canonical, malformed historical rows can fall back safely, and combined specialized displays never emit duplicate `NAME - NAME` values.
- [x] 2.2 Update the customer DataTable and Customer detail labels to show canonical `customer_name` while retaining `contact_name` only as separately identified supplemental information.
- [x] 2.3 Update the shared Customer search dropdown and legacy `CustomerLoader` PHP/Blade pair to search compatibly but label, select, mount, and dispatch customers using canonical-name semantics.
- [x] 2.4 Update remaining ordinary customer reads in Sales/POS surfaces, including sale dispatch headers and POS customer search/resolution mappings, to use the centralized canonical/display rules without changing payload keys, routes, or Livewire events.

## 3. Customer Creation and Editing Normalization

- [x] 3.1 Update full Customer create/edit validation, persistence, and form labels so `customer_name` is required and editable, `contact_name` is nullable, and clearing the contact is supported.
- [x] 3.2 Update the Sales create/edit shared customer quick-add modal to persist the required `customer_name` and preserve an omitted contact as null/unset instead of copying the canonical name.
- [x] 3.3 Update the legacy customer quick-add component to collect/persist canonical `customer_name`, keep `contact_name` optional, and preserve its existing event contract.
- [x] 3.4 Update POS customer creation to persist its single supplied name only as `customer_name`, leave `contact_name` null/unset, and preserve the endpoint’s response keys and checkout-selection behavior.
- [x] 3.5 Confirm no customer schema migration, historical data backfill, merge, or rewrite was introduced.

## 4. Global Customer Selection Consistency

- [x] 4.1 Add Settings feature coverage proving POS walk-in configuration lists and accepts customers with a different or null `setting_id`, while still rejecting a missing customer ID.
- [x] 4.2 Remove the active-setting predicate from Settings walk-in customer options and existence validation without changing setting-owned transaction or split-posting ownership behavior.
- [x] 4.3 Review all production customer loaders/selectors for `customers.setting_id` predicates and remove only predicates that incorrectly scope customer identity, retaining transaction/report ownership scopes outside the customer master query.

## 5. Verification and Protected Import Boundary

- [x] 5.1 Run focused Customer CRUD/DataTable, Sales Livewire customer-selection, POS customer store/search, receipt display, and Settings walk-in mapping tests.
- [x] 5.2 Run `php artisan test` with focused filters for the affected People, Sales, POS, and Settings regression classes, then run `composer test:fresh-sqlite` when feasible.
- [x] 5.3 Review the final diff and verify `Modules/Sale/Services/SalesImportService.php` and other sales-import implementation files have no changes attributable to this work.
