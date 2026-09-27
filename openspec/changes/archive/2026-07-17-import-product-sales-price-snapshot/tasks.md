## 1. Import Type and Upload Entry Point

- [x] 1.1 Add the `sales_price_snapshot` constant and display label behavior to `ProductImportBatch` without changing existing import type semantics.
- [x] 1.2 Add product sales-price snapshot GET/POST routes and controller actions protected by product edit authorization.
- [x] 1.3 Build the dedicated XLSX upload view with format guidance, mutation-scope warnings, and navigation from the existing product import area.
- [x] 1.4 Validate `.xlsx` submissions, store the workbook and checksum, create the typed batch, dispatch the dedicated processor, and redirect to batch details.
- [x] 1.5 Add feature tests for authorized upload, unauthorized access, unsupported file rejection, batch creation, file persistence, and queued job dispatch.

## 2. Workbook Reading and Row Staging

- [x] 2.1 Implement a read-only PhpSpreadsheet workbook reader that selects the active worksheet and normalizes BOM, case, and whitespace in headers.
- [x] 2.2 Validate required `Name*` and `SellPrice` headers while treating `ProductCode` as optional, and fail the batch before mutation for missing headers or unreadable workbooks.
- [x] 2.3 Stage workbook rows in bounded inserts with worksheet row numbers and raw name, product code, and selling-price values.
- [x] 2.4 Implement and unit-test Accurate-compatible decimal parsing for comma-grouped prices, ordinary numeric cells, blank, zero, negative, non-numeric, non-finite, and out-of-range values.
- [x] 2.5 Add workbook-reader tests covering valid Accurate-style input, header normalization, optional product code, corrupt input, and a workbook sized comparably to the supplied 4,859-row export.

## 3. Owner and Product Resolution

- [x] 3.1 Build a sales-price snapshot row resolver that reuses `SalesImportMarkerResolver` for Daizu priority, leading-asterisk, trailing-TP, default-owner, clean-name, canonical normalization, and alias behavior.
- [x] 3.2 Resolve the owner company to an existing setting and record an actionable row error when the required setting is unavailable.
- [x] 3.3 Implement deterministic matching by unique case-insensitive product code, unique whitespace-normalized clean-name exact match, and unique shared canonical-name fallback.
- [x] 3.4 Reject code/name disagreement and exact or canonical database collisions with candidate IDs and names instead of guessing.
- [x] 3.5 Mark unmatched products and non-positive prices as skipped without creating or mutating products, units, categories, brands, stocks, or transactions.
- [x] 3.6 Add focused tests for every owner rule, marker removal, aliases, exact and canonical matching, blank codes, unmatched products, missing settings, and ambiguous candidates.

## 4. Target Validation and Price Synchronization

- [x] 4.1 Pre-resolve staged rows and group valid candidates by `(product_id, setting_id)` before applying any price mutation.
- [x] 4.2 Reject every member of a target group containing conflicting positive prices, and collapse equivalent duplicate targets to a single mutation with duplicate row outcomes.
- [x] 4.3 Implement per-row transactional update-or-create behavior that sets `sale_price`, `tier_1_price`, and `tier_2_price` to the imported `SellPrice` for only the resolved owner setting.
- [x] 4.4 Preserve existing purchase prices and tax IDs, and verify that legacy product prices, stock, bundle prices, unit-conversion prices, and other settings are not mutated.
- [x] 4.5 Record raw and clean identity, marker, match strategy, product and owner context, imported price, previous tiers, resulting tiers, and changed/unchanged state in row result metadata.
- [x] 4.6 Finalize processed, successful, skipped/duplicate, and error outcomes consistently while allowing unrelated valid rows to complete after row-level failures.

## 5. Batch Monitoring and Operational Safety

- [x] 5.1 Add distinct sales-price snapshot labels and filtering context to product import batch list and detail screens.
- [x] 5.2 Add a sales-price-specific row table showing resolved product and owner, imported value, previous/resulting tiers, match strategy, changed state, and actionable non-applied reasons.
- [x] 5.3 Ensure sales-price snapshot batches never receive an undo availability timestamp and cannot expose the existing stock-oriented undo action.
- [x] 5.4 Add UI tests for typed batch presentation, changed and unchanged rows, skipped and ambiguous rows, filters, metadata rendering, and absence of undo.

## 6. End-to-End Verification

- [x] 6.1 Add integration tests proving different markers can apply different prices to the same global product while leaving every unrelated setting unchanged.
- [x] 6.2 Add integration tests proving all three selling tiers are synchronized together and purchase prices, tax IDs, product data, and stock remain unchanged.
- [x] 6.3 Add integration tests for conflicting duplicate targets, equivalent duplicates, zero prices, ambiguous database identities, partial success, and transactional rollback.
- [x] 6.4 Run the focused Product module and import test suites with `php artisan test` filters, then run `composer test:fresh-sqlite` for migration and cross-feature confidence.
- [x] 6.5 Perform a read-only or staging verification with `TIGA_COMPUTER_ProductExport_12_07_2026.xlsx` and reconcile matched, skipped, ambiguous, error, and owner-target counts against the discovery baseline before production use.

## 7. Bug Fixes

- [x] 7.1 P1 - Fix product matching logic to require exact cleaned-name matching followed by normalization of both sides, and reject code/name disagreement when a code exists.
- [x] 7.2 P1 - Skip blank and zero prices instead of overwriting tiers with zero.
- [x] 7.3 P1 - Change permission from `products.create` to product edit authorization (e.g., `products.edit`) for product sales price snapshot routes.
- [x] 7.4 P1 - Fix batch-level failure writing to nonexistent `error_message` column. Use appropriate logging or correct the database schema/model if the column is meant to exist, or map to an existing column (like `errors`).
- [x] 7.5 P2 - Correct outcome classification for skipped (unmatched/non-positive rows) and duplicate rows (identify equivalent duplicates instead of counting as successful).
- [x] 7.6 P2 - Expand audit information in result metadata to include raw/clean names, marker, match strategy, product/owner names, and ambiguity candidates. Update detail view to show all three tiers and use correct page-level badge.
- [x] 7.7 P2 - Add price range validation to reject values above 99,999,999.99 before persistence.
