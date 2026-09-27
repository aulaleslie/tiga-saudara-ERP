## 1. Lifecycle Inventory and Decisions

- [x] 1.1 Inventory delete routes/actions, selectors, quick-add flows, imports, defaults, authoritative write boundaries, historical resolvers, and foreign keys for products, customers, suppliers, taxes, payment methods, payment terms, locations, units, and chart of accounts.
- [x] 1.2 Document per-master default handling and structural deactivation guards, including required locations and chart-of-account records.
- [x] 1.3 Decide and document backward-compatible permission mapping for deactivate and reactivate operations.

## 2. Schema and Shared Lifecycle Foundation

- [x] 2.1 Add indexed non-null `is_active` fields with active defaults/backfill to covered master tables that lack an equivalent lifecycle field, with MySQL/MariaDB and SQLite-compatible migrations.
- [x] 2.2 Add explicit active/eligible scopes and boolean casts to covered Eloquent models without introducing a global scope.
- [x] 2.3 Implement reusable authorized deactivate/reactivate behavior with validation for defaults and structurally required records.
- [x] 2.4 Keep product active eligibility separate from `merged_into_id` and preserve existing product merge resolution behavior.

## 3. Master Administration

- [x] 3.1 Replace product, customer, and supplier delete actions with deactivate/reactivate actions, lifecycle badges, and active/inactive list filters.
- [x] 3.2 Replace tax, payment-method, and payment-term delete actions with deactivate/reactivate actions, lifecycle badges, and active/inactive list filters.
- [x] 3.3 Replace location, unit, and chart-of-account delete actions with guarded deactivate/reactivate actions, lifecycle badges, and active/inactive list filters.
- [x] 3.4 Make legacy destroy endpoints non-destructive and ensure direct destructive application calls for covered records fail safely.

## 4. Operational Selection and Write Enforcement

- [x] 4.1 Filter inactive products, units, taxes, and locations from new Sale, Purchase, POS, Quotation, Adjustment, Transfer, Expense, and other touched transaction selectors and search endpoints.
- [x] 4.2 Filter inactive customers and suppliers from new-document selectors, autocomplete components, quick-add reuse paths, and imports.
- [x] 4.3 Filter inactive payment methods, payment terms, and chart-of-account choices from new payment, settlement-configuration, and accounting activity.
- [x] 4.4 Add authoritative server-side active eligibility validation to Sale, Purchase, Quotation, Adjustment, Transfer, Expense, and other touched document creation/posting boundaries.
- [x] 4.5 Add authoritative active eligibility revalidation to POS staged-cart checkout and finalization boundaries.
- [x] 4.6 Update relevant imports and APIs to reject inactive identifiers without partially persisting transactional data.

## 5. Existing Documents and Historical Workflows

- [x] 5.1 Preserve each existing draft's currently referenced inactive master records while requiring active records for replacements and newly added lines.
- [x] 5.2 Ensure posted document detail/history views resolve inactive products, parties, taxes, units, locations, payment data, and accounts.
- [x] 5.3 Ensure receivable and payable settlement inherits inactive customers or suppliers from source documents while requiring newly selected payment configuration to be active.
- [x] 5.4 Ensure sale, purchase, and POS return/refund/reversal workflows resolve inactive master data from their source transactions.
- [x] 5.5 Review and adjust touched report queries so inactive master references remain included and retain usable labels and grouping.

## 6. Referential Integrity Hardening

- [x] 6.1 Replace confirmed unsafe cascade or null-on-delete constraints from covered masters to historical transactional/accounting rows with restrictive behavior where compatible.
- [x] 6.2 Verify lifecycle updates leave transaction, inventory, payment, return, report, and accounting references unchanged.
- [x] 6.3 Add defensive coverage proving covered application paths cannot physically or soft-delete master records or cascade-delete their dependents.

## 7. Focused Verification

- [x] 7.1 Add focused feature tests for authorized deactivation/reactivation, list status/filter behavior, default guards, and legacy delete endpoint safety for each touched master group.
- [x] 7.2 Add focused selector and submission tests proving inactive records are excluded and crafted or stale submissions are rejected atomically.
- [x] 7.3 Add focused regression tests proving existing drafts preserve their current inactive references while rejecting replacement with another inactive record.
- [x] 7.4 Add focused regression tests for historical document display, reports, source-based returns/reversals, and existing receivable/payable settlement with inactive parties.
- [x] 7.5 Add a focused product regression test proving ordinary deactivation does not set `merged_into_id` or alter duplicate-product merge behavior.
- [x] 7.6 Run only the focused PHPUnit/Pest filters covering the implementation and directly affected regression paths, and record the commands and results.
