## 1. Import Workflow Setup

- [x] 1.1 Add the `dual_company_tier_price` product-import batch type and ensure import index/detail labels distinguish it from Accurate price-and-stock snapshots.
- [x] 1.2 Add product-edit-authorized routes, controller actions, and a dedicated upload page for the dual-company tier-price workbook.
- [x] 1.3 Validate the submitted `.xlsx` extension and readable workbook structure, persist the upload, create the batch, queue processing, and redirect to the batch detail page without stock/location requirements.

## 2. Workbook Staging and Resolution

- [x] 2.1 Implement a dedicated queued processor that validates exactly the CV TIGA NUSA COMPUTER and CV TOP IT INTERNUSA worksheets, rejects unexpected sheets, and validates required row-4 headers.
- [x] 2.2 Stage both worksheets' data rows with worksheet/company provenance and raw selling-tier values in the existing import-row audit store.
- [x] 2.3 Resolve each worksheet's exact-name setting and each row's uniquely normalized catalog product name without owner-marker routing, product creation, or price-row creation.
- [x] 2.4 Parse independent selling-tier cells, preserve blanks as no-change values, accept numeric zero, and report invalid/out-of-range values and all-blank rows deterministically.

## 3. Price Mutation and Audit

- [x] 3.1 Group rows by product/company target and reject duplicate groups that conflict on any commonly supplied selling-tier value while marking equivalent later rows as duplicates.
- [x] 3.2 Apply valid target tier updates atomically to the existing company-scoped `product_prices` row, preserving unsupplied tiers and all non-selling-price fields.
- [x] 3.3 Record worksheet/company, product match strategy, supplied tiers, previous tiers, resulting tiers, changed status, and row-level errors in result metadata.
- [x] 3.4 Add a dedicated batch-detail presentation for this import type and ensure no undo action is offered.

## 4. Verification

- [x] 4.1 Add controller/route tests for authorization, invalid uploads, batch creation, queue dispatch, and redirect behavior.
- [x] 4.2 Add processor tests for required-sheet/header validation, extra-sheet rejection, both-company isolation, and no stock or purchase-cost mutation.
- [x] 4.3 Add processor tests for independent all-tier, partial-tier, blank-tier, and explicit-zero updates, including preservation of non-selling fields.
- [x] 4.4 Add processor tests for unmatched/ambiguous products, missing company price rows, conflicting duplicates, equivalent duplicates, and atomic rollback on persistence failure.
- [x] 4.5 Run the focused Product-module import tests and `php artisan test` for the affected test classes.
