## 1. Matching Foundation

- [x] 1.1 Extract or introduce a reusable owner/product-name resolver for sales import marker semantics covering Daizu keywords, `*` prefix, ` TP` suffix, and unmarked `PERDANA` fallback.
- [x] 1.2 Add focused resolver tests proving HPP import and sales import owner routing stay aligned for Daizu, asterisk, TP suffix, and unmarked products.
- [x] 1.3 Add HPP-specific numeric parsing helpers for `Mutasi` and `Harga Rata-Rata`, including decimal strings and negative sales quantities.

## 2. Import Type and Upload Flow

- [x] 2.1 Add a `sales_hpp_snapshot` product import type constant or equivalent local convention for batches and views.
- [x] 2.2 Add a Product List upload entry point for sales HPP snapshot CSV files with required-header validation.
- [x] 2.3 Extend product import batch type detection or dedicated upload handling to recognize HPP ledger headers.
- [x] 2.4 Stage HPP CSV rows into `product_import_rows.raw_json` with source transaction type, reference number, raw product name, cleaned product name, source quantity, source HPP, and relevant display fields.

## 3. HPP Snapshot Processing

- [x] 3.1 Add HPP import branch handling in `ProcessProductImportBatch` or a dedicated service invoked by that job.
- [x] 3.2 Skip non-`Sales Invoice` rows without updating sale details.
- [x] 3.3 Resolve the target owner setting from raw `Barang` using shared sales import marker semantics.
- [x] 3.4 Match exactly one sale detail by `sales.imported_sales_reference_number`, resolved `sales.setting_id`, normalized clean product name, and `abs(Mutasi)` quantity tolerance.
- [x] 3.5 Mark missing sale, missing detail, ambiguous match, invalid HPP, missing owner setting, and quantity mismatch rows as row-level errors without writing snapshots.
- [x] 3.6 Overwrite matched `sale_details.cost_unit_snapshot`, `cost_total_snapshot`, `cost_snapshot_source`, and `cost_snapshot_at` using the CSV HPP row as source of truth.
- [x] 3.7 Store row `result_metadata` for successful and failed rows, including previous snapshot values where practical, matched sale/detail IDs, resolved owner, source quantity, imported HPP, and resulting totals.

## 4. Import Visibility

- [x] 4.1 Update product import batch list badges/labels to display sales HPP snapshot imports distinctly from product and stock snapshot imports.
- [x] 4.2 Update product import batch detail rendering to show HPP-specific row fields and error messages.
- [x] 4.3 Ensure re-running an HPP snapshot import clearly reports successful overwrites rather than treating existing snapshots as skipped.

## 5. Tests

- [x] 5.1 Add feature tests for accepting valid HPP headers and rejecting missing required headers.
- [x] 5.2 Add processing tests for skipping non-sales transaction rows.
- [x] 5.3 Add processing tests for asterisk, TP suffix, unmarked, and Daizu owner routing to the correct split imported sale.
- [x] 5.4 Add processing tests proving exact reference, owner, normalized product name, and quantity matching updates one sale detail.
- [x] 5.5 Add processing tests for quantity mismatch, no match, and ambiguous match failures with no snapshot writes.
- [x] 5.6 Add processing tests proving the importer overwrites existing snapshots and sets the HPP import source label.
- [x] 5.7 Add UI/response tests proving HPP import batches and row metadata are visible in Product import pages.

## 6. Verification

- [x] 6.1 Run focused tests for product import and HPP snapshot import behavior.
- [x] 6.2 Run focused tests for sales import owner marker behavior to confirm no regression.
- [x] 6.3 Run `php artisan test` or `composer test:fresh-sqlite` when practical for broader confidence.
