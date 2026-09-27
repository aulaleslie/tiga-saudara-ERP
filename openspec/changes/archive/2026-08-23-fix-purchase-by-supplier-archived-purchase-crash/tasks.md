## 1. Regression Coverage

- [x] 1.1 Add a focused Purchase by Supplier report test with matching active and archived purchases in the same setting and date range, asserting filter/render success and exclusion of archived detail rows from counts and monetary results.
- [x] 1.2 Add or extend a focused export-data assertion proving the shared query excludes the archived purchase without retesting spreadsheet formatting.

## 2. Query Fix

- [x] 2.1 Add the non-archived purchase constraint to `PurchaseBySupplierReportQueryService::build()` so all direct consumers operate on the same eligible dataset.
- [x] 2.2 Confirm by code inspection that filter snapshots, screen pagination and totals, and Excel/CSV actions continue to use the shared query builder without a bypass path.

## 3. Focused Verification

- [x] 3.1 Run the focused Purchase by Supplier report regression test file or the narrowest supported test filter covering the touched behavior.
- [x] 3.2 Reproduce the Dunia Computer August 1–31, 2026 query through Tinker and verify purchase `DC-BL-2026-08-00008` contributes no report rows while matching active purchases remain present.
