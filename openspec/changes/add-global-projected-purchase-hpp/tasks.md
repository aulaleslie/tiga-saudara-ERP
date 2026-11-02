# Tasks

## 1. Shared outstanding source and projection calculation

- [x] 1.1 Add a reusable approved-outstanding product-line query using the existing in-delivery rules; verify focused fixtures cover multiple businesses, partial receipts, pending/rejected receipts, archived/unapproved/fully received purchases, duplicate product lines, fractional quantities and per-line over-receipt clamping. If refactoring the inventory query, run `CrossBusinessInDeliveryStockServiceTest`.
- [x] 1.2 Add global projected-HPP aggregation using canonical base quantities and `PurchaseCostHelper` valuation; verify focused PHP tests cover weighted current stock plus incoming cost, discount/tax parity, no outstanding purchases, zero stock, nonpositive resulting stock, fractional precision and no database mutations.

## 2. Multi-business pricing presentation

- [x] 2.1 Wire the projection into `CrossBusinessPriceController::edit` using its existing global-HPP baseline; verify focused feature tests cover identical values across business rows, missing price rows, divergent-average fallback and stockless `Tidak berlaku` display.
- [x] 2.2 Add the permanently read-only `Proyeksi HPP` column, exact tooltip and permission-aware new-tab source link; verify rendered-response tests cover currency magnitude, tooltip, link parameters/target, absence without global access and no projection write path. Run focused existing price-access/backend regression tests to preserve saves and HPP operations.

## 3. Global source purchase drilldown

- [x] 3.1 Validate and pass durable product projection context through the global controller, workspace partial and locked Livewire state; add the shared outstanding-product query branch without changing normal global payment eligibility. Verify focused request/Livewire tests include APPROVED and partially received source purchases, fully paid contributors, distinct headers, wrong-product exclusion, invalid IDs and unauthorized direct access.
- [x] 3.2 Preserve product context through refresh, pagination, sorting, search, optional business/date filters and reset; label the source scope and hide payment summary cards only in this mode. Verify focused Livewire tests cover initial unrestricted scope, filter composition, empty results and unchanged standalone/supplier-embedded normal views.
- [x] 3.3 Enable source-document navigation with validated projection context while preserving normal payment candidate and action guards; verify focused request tests cover approved source detail, unrelated/noncontributing detail rejection, permission denial and unchanged direct payment eligibility.

## 4. Focused integration verification and human handoff

- [x] 4.1 Run the new focused projection/drilldown tests plus relevant existing product pricing, inventory-query (if touched), and global purchase filter/eligibility tests with `php artisan test --filter=...`; record actual commands and results. Confirm viewing projection/source lists leaves stored stock and prices unchanged. Focused verification is sufficient; no full-suite run is required.
- [x] 4.2 Deliver a human browser checklist covering column placement/format, tooltip, read-only edit/cancel behavior, new-tab navigation, cross-business source rows, approved detail, no-source display and access-based link visibility; record browser validation as human-owned and pending until a human reports results. Do not execute automated browser tests or browser tools.

## Workflow follow-up

- A human performs the browser checklist and records results; do not report these checks as passed based only on PHP tests.
- Review and archive the change after implementation and the project's human review requirements are satisfied.

## Verification record

- `php artisan test --filter=CrossBusinessInDeliveryStockServiceTest` — PASS, 5 tests.
- `php artisan test --filter=GlobalProjectedHppServiceTest` — PASS, 4 tests; covers discount/tax parity, fractional quantities, zero/nonpositive stock, no outstanding purchases, and unchanged stock/price records.
- `php artisan test --filter='CrossBusinessPriceBackendTest|CrossBusinessPriceAccessTest|GlobalProjectedHppServiceTest'` — PASS after correcting the test fixture to give the price-only user the required price permission.
- `php artisan test Modules/Product/Tests/Feature/CrossBusinessPriceMaskTest.php` — PASS, 19 tests.
- `php artisan test Modules/Product/Tests/Feature/CrossBusinessPriceBackendTest.php` — PASS, 31 tests.
- `php artisan test Modules/Purchase/Tests/Feature/GlobalPurchasePaymentTableTest.php` — PASS, 22 tests.
- `php artisan test Modules/Purchase/Tests/Feature/GlobalPurchasePaymentViewTest.php` — PASS, 4 tests.
- `php artisan test Modules/Purchase/Tests/Feature/GlobalPurchasePaymentAuthTest.php` — PASS, 4 tests.
- `php artisan test Modules/Purchase/Tests/Feature/GlobalPurchasePaymentFiltersTest.php --filter='apply|search|pagination|reset'` — PASS, 11 selected tests.
- `php artisan test Modules/Purchase/Tests/Feature/GlobalPurchasePaymentPartialStatesTest.php` — PASS, 18 tests.
- `php artisan test Modules/Purchase/Tests/Feature/EmbeddedGlobalPurchasePaymentIsolationTest.php` — PASS, 7 tests.
- `php artisan test Modules/Purchase/Tests/Feature/GlobalPurchasePaymentFiltersTest.php` — 33 passed, 1 unrelated existing selector assertion failed because Blade escapes an apostrophe in the random company name while the test expects raw text.

The browser checklist remains human-owned and pending; no browser test or browser tool was run.
