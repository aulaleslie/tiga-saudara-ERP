## 1. Import Entry Point

- [x] 1.1 Decide whether the stock snapshot import is a distinct product upload mode or a dedicated product stock upload route, preserving existing product import behavior.
- [x] 1.2 Add upload/header validation for `Product Code`, `Product Name`, `Unassigned`, `Total Quantity`, and `Product Unit`.
- [x] 1.3 Store the uploaded stock snapshot file in the product import storage area and create a product import batch for processing.

## 2. Row Staging and Normalization

- [x] 2.1 Add row mapping for stock snapshot CSV columns into normalized row payload keys.
- [x] 2.2 Implement product-name marker parsing for leading `*`, trailing `TP`, and default owner marker cases.
- [x] 2.3 Normalize clean product names consistently before matching or creating products.
- [x] 2.4 Preserve raw marker, clean product name, `Unassigned`, `Total Quantity`, product unit, and product code in row payload for audit.

## 3. Owner and Location Resolution

- [x] 3.1 Resolve `*` rows to CV TIGA NUSA COMPUTER, `TP` rows to CV TOP IT INTERNUSA, and default rows to PERDANA.
- [x] 3.2 Resolve the first location for each owner setting and cache setting/location lookups during batch processing.
- [x] 3.3 Mark rows as errors when the required owner setting or first owner location cannot be found.

## 4. Product Matching and Creation

- [x] 4.1 Match existing products by normalized clean product name and product code without creating owner-specific duplicate products.
- [x] 4.2 Create missing stock-managed products with clean product name, optional product code, and resolved product unit.
- [x] 4.3 Create or reuse product unit records using existing module conventions.
- [x] 4.4 Seed required product price rows with conservative defaults for all settings when creating a missing product.

## 5. Stock Overwrite and Audit

- [x] 5.1 Parse `Total Quantity` as the authoritative stock quantity, including zero and negative values.
- [x] 5.2 Create or update the target `product_stocks` row for the resolved product and first owner location.
- [x] 5.3 Overwrite stock buckets and product quantity projections consistently with existing stock conventions.
- [x] 5.4 Record a stock transaction or equivalent audit entry with previous quantity, after quantity, owner setting, location, user, and import reason.
- [x] 5.5 Ensure each row is processed atomically so row failure does not partially update product or stock state.

## 6. Monitoring and Result Visibility

- [x] 6.1 Show row status, error message, raw payload, product reference, and stock effect details on the product import batch detail page.
- [x] 6.2 Ensure batch counters reflect imported, skipped, and error rows for the stock snapshot import.
- [x] 6.3 Keep the existing product import list usable for both current product imports and stock snapshot imports.

## 7. Verification

- [x] 7.1 Add focused tests for header validation and stock snapshot row staging.
- [x] 7.2 Add tests for marker routing and clean product name normalization.
- [x] 7.3 Add tests for creating missing products and reusing one global product across multiple owner markers.
- [x] 7.4 Add tests for overwriting positive, zero, and negative stock quantities at the first owner location.
- [x] 7.5 Add tests for row-level errors when owner setting or location is missing.
- [x] 7.6 Run focused Product import tests and a relevant broader Laravel test command if feasible.

## 8. Completion Audit Findings

- [x] 8.1 Confirm current backend has partial stock snapshot support through `product_import_batches.import_type = stock_snapshot`, CSV header auto-detection, marker owner routing, product creation/reuse, stock overwrite, product quantity delta update, and `ADJ` transaction creation.
- [x] 8.2 Confirm current UI still reuses the generic product upload page and generic product template download, so stock snapshot import is not explicit to users.
- [x] 8.3 Confirm current batch/list/detail views do not yet make stock snapshot import type and stock effect fields first-class visible data.
- [x] 8.4 Confirm focused stock snapshot test coverage currently exists as a happy-path feature test, but does not cover all completion risks below.

## 9. UI Completion

- [ ] 9.1 Add an explicit stock snapshot import entry point or upload mode in the Product import area while preserving existing product import behavior.
- [ ] 9.2 Add a stock snapshot CSV template download with `Product Code`, `Product Name`, `Unassigned`, `Total Quantity`, and `Product Unit` columns.
- [ ] 9.3 Show marker routing guidance on the stock snapshot upload UI: leading `*` -> CV TIGA NUSA COMPUTER, trailing `TP` -> CV TOP IT INTERNUSA, no marker -> PERDANA.
- [ ] 9.4 Make the selected/detected import type visible before upload where practical and on the created batch after upload.
- [ ] 9.5 Show import type in the import batch list so product imports and stock snapshot imports can be distinguished.
- [ ] 9.6 Enhance the import batch detail page for stock snapshot rows to show clean product name, resolved owner, target location, imported total quantity, previous quantity, after quantity, tax/non-tax bucket effect, stock transaction reference, row status, and actionable errors.
- [ ] 9.7 Add UI/request tests for stock snapshot upload page visibility, template headers, import type visibility, marker guidance, and stock-effect row rendering.

## 10. Backend Completion and Hardening

- [ ] 10.1 Persist successful stock snapshot row references to the touched `product_stocks` row and created stock transaction using existing `created_stock_id` and `created_txn_id` fields or an equivalent row result metadata convention.
- [ ] 10.2 Persist or derive row-level result metadata needed by the UI: raw marker, clean product name, resolved owner setting, target location, previous quantity, after quantity, and tax/non-tax bucket deltas.
- [ ] 10.3 Add real `upload-data/warehouse_stock_quantity.csv` behavior coverage, especially blank quoted product codes and quoted product names containing inches marks.
- [ ] 10.4 Add missing owner setting error coverage that proves no product stock, product quantity, or transaction mutation occurs for that row.
- [ ] 10.5 Add missing owner location error coverage that proves no product stock, product quantity, or transaction mutation occurs for that row.
- [ ] 10.6 Add PKP bucket routing tests proving PKP owners write `quantity_tax = Total Quantity` and `quantity_non_tax = 0`, including transaction bucket deltas.
- [ ] 10.7 Add non-PKP bucket routing tests proving non-PKP owners write `quantity_non_tax = Total Quantity` and `quantity_tax = 0`, including transaction bucket deltas.
- [ ] 10.8 Add zero quantity tests that prove stock rows are created or updated to zero, transactions are audited, and product aggregate quantity remains consistent.
- [ ] 10.9 Add negative quantity tests that prove stock rows, tax/non-tax buckets, transactions, and product aggregate quantity all preserve the negative snapshot value.
- [ ] 10.10 Add stock transaction audit tests covering product, owner setting, target location, previous quantity, after quantity, user, reason, and signed delta.
- [ ] 10.11 Add product quantity/bucket consistency tests across multiple owner-location rows for the same clean product.
- [ ] 10.12 Run focused Product stock snapshot import tests and a broader relevant Laravel test command once implementation tasks are complete.
