## Context

The ERP already supports CSV-backed import batches for product data and stock snapshots through `Modules/Product`, and sales imports through `Modules/Sale`. Sales imports can split one source invoice into multiple `sales` rows by product-name owner routing: Daizu product keywords route to Daizu, a leading `*` routes to `CV TIGA NUSA COMPUTER`, a trailing ` TP` routes to `CV TOP IT INTERNUSA`, and unmarked products route to `PERDANA`. The resulting `sale_details` rows currently receive cost snapshots through live average-price lookup or historical backfill.

The HPP source file is a historical inventory ledger. For this change, only `Tipe Transaksi = Sales Invoice` rows are relevant. `No. Transaksi` identifies the source imported sale reference, `Barang` carries the same owner marker and product identity used by sales import, `Mutasi` carries the source sold quantity as a negative value, and `Harga Rata-Rata` carries the authoritative historical average purchase price at sale time.

## Goals / Non-Goals

**Goals:**
- Provide an explicit product-list import mode for HPP sales snapshots.
- Apply HPP values only to sale details created by prior sales import.
- Match split-owner imported sales using the same owner-marker semantics as sales import.
- Treat the CSV HPP ledger as authoritative and overwrite matched sale detail cost snapshots.
- Preserve row-level observability for skipped rows, matching failures, quantity mismatches, ambiguous matches, and successful updates.

**Non-Goals:**
- Re-import or rewrite sales, sale headers, dispatches, payments, stock quantities, products, purchases, or inventory transactions.
- Recalculate moving-average cost from purchases during this import.
- Replace the existing sales cost snapshot service or historical backfill command.
- Infer HPP for rows that do not exist in the uploaded source ledger.

## Decisions

### Add a new Product import type

Use a new `product_import_batches.import_type` value such as `sales_hpp_snapshot` rather than overloading product import or stock snapshot import.

Rationale: the workflow belongs under Product List because the existing import monitor and upload surfaces already live there, but the behavior is neither product creation nor stock quantity overwrite. A distinct import type keeps validation, row rendering, and undo policy explicit.

Alternatives considered:
- Extend stock snapshot import: rejected because this import does not mutate `product_stocks` or create stock adjustment transactions.
- Add a Sales module import page: rejected for now because the user explicitly wants the function under Product List and the existing Product import batch UI already supports import-type-specific processing.

### Reuse sales import owner routing

Resolve each source row's target owner from raw `Barang` using the same rules as `SalesImportService`: Daizu keywords first, then `*`, then ` TP`, then unmarked `PERDANA` fallback.

Rationale: sales import creates split owner sale documents from these markers. The HPP importer must target the same `sales.setting_id` or it can update the wrong owner copy of a source invoice.

Alternatives considered:
- Match every sale under `imported_sales_reference_number` and then choose by product name: rejected because the same clean product can appear in different owner splits.
- Use CSV `Tag`: rejected because current sales import ownership deliberately ignores `Tag` for non-Daizu rows.

### Match by reference, owner, clean product, and quantity

For each eligible CSV row, find one sale detail through:
- `sales.imported_sales_reference_number = No. Transaksi`
- `sales.setting_id = resolved owner setting`
- normalized `sale_details.product_name = cleaned normalized Barang`
- `sale_details.quantity = abs(Mutasi)` within a small decimal tolerance

Rationale: source invoices may split into multiple sales. Product name and quantity together guard against accidentally updating the wrong detail when references and products are reused.

Alternatives considered:
- Ignore quantity and update all name matches: rejected because it hides split or duplicate-line ambiguity.
- Use `product_id` only: rejected because the source file has product names and markers, and historical imported products may have been normalized during sales import.

### CSV quantity and HPP are source of truth

On a successful match, set:
- `cost_unit_snapshot = Harga Rata-Rata`
- `cost_total_snapshot = abs(Mutasi) * Harga Rata-Rata`
- `cost_snapshot_source = IMPORTED_HPP_SNAPSHOT`
- `cost_snapshot_at = now()`

Rationale: the business goal is to fill HPP snapshots with the historical average purchase price from the source ledger at sale time. Quantity matching ensures the source row corresponds to the parent sale detail, while the stored total follows the authoritative source quantity.

Alternatives considered:
- Use existing `sale_details.quantity` for `cost_total_snapshot`: rejected because the user clarified the CSV is the source of truth. The importer should reject mismatches rather than silently calculate from a different quantity.

### Fail closed on ambiguous rows

Rows with no matching sale, missing owner setting, missing product identity, missing/invalid HPP, multiple matching sale details, or quantity mismatch are row errors. Non-sales transaction rows are skipped, not errors.

Rationale: this import changes financial report inputs. Partial success is acceptable, but ambiguous updates are not.

### Keep updates narrowly scoped

Do not change sales import, stock normalization, product prices, current average purchase prices, stock rows, or inventory transactions.

Rationale: the import is a post-sales-import correction of report snapshots only. Other financial and inventory records have separate import and normalization workflows.

## Risks / Trade-offs

- [Risk] Owner routing logic diverges from `SalesImportService` over time. → Mitigation: extract shared resolver logic or add focused tests covering Daizu, `*`, ` TP`, and unmarked rows for both sales import and HPP import.
- [Risk] Historical data contains duplicate sale details with the same normalized name and quantity under one imported owner sale. → Mitigation: mark the row as ambiguous and require manual investigation rather than updating multiple rows.
- [Risk] Quantity precision differs between source CSV and persisted sale detail decimal scale. → Mitigation: compare numeric quantities using a small tolerance compatible with the `sale_details.quantity` decimal scale.
- [Risk] Users accidentally upload a product/stock CSV into the HPP import mode. → Mitigation: require HPP-specific headers and fail the batch early when required columns are missing.
- [Risk] Re-running an HPP import overwrites prior snapshots. → Mitigation: make overwrite behavior explicit in UI labels and row metadata because this import is authoritative by design.

## Migration Plan

1. Add the new import type and upload route/page or upload option under Product List.
2. Extend CSV detection or dedicated upload handling for required HPP headers.
3. Add row staging fields to `raw_json`/`result_metadata`; avoid schema changes unless row result metadata needs dedicated searchable columns.
4. Process eligible rows in the existing queue path and update only matched `sale_details` rows.
5. Add import batch detail rendering for HPP snapshot results.
6. Deploy without running any automatic backfill. Users upload HPP source files explicitly.

Rollback is code rollback only for future imports. Already-updated `sale_details` are authoritative data changes; reverting them would require restoring from database backup or a separately captured before/after audit payload if implemented.

## Open Questions

- Should row metadata store previous `cost_unit_snapshot`, `cost_total_snapshot`, and `cost_snapshot_source` for audit-only visibility?
- Should the UI expose a dry-run validation mode before writing, or is row-level failure reporting after upload sufficient for the first implementation?
- Should `IMPORT_HPP_SNAPSHOT` or `IMPORTED_HPP_SNAPSHOT` be the final source label? The design uses `IMPORTED_HPP_SNAPSHOT` for readability.
