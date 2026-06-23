## Context

The Reports > Produk landing tab currently lists `Ringkasan persediaan barang` as an unavailable placeholder. The sample files under `report-sample/ringkasan-persediaan-barang/` define a Mekari/Jurnal-style report with a single as-of date, period presets, stock-status/category/product filters, sortable product-level rows, pagination, CSV export, and XLSX export. The sample exports use columns `Kode Produk`, `Nama Produk`, `Stok di tangan`, `Batas Minimum`, `Satuan`, `Harga Rata-rata`, and `Nilai`; the XLSX adds company/date/currency/sort metadata before the table.

Neighboring samples clarify the report boundary. `Kuantitas stok gudang` and `Nilai stok gudang` include warehouse filters and warehouse-shaped exports, while `Ringkasan persediaan barang` does not. `Nilai persediaan barang` uses transaction history and has the same grand total as the Ringkasan sample for 21/06/2026, which indicates Ringkasan should be an as-of inventory valuation summary rather than a current-stock snapshot with a date label.

The existing Laravel app already has the building blocks:

- `Modules/Reports` route/controller/view patterns for report pages.
- `app/Livewire/Reports/*` components for filters, pagination, and export actions.
- `app/Exports/*` report exports using Maatwebsite Excel.
- `products.product_code` is nullable, `products.product_stock_alert` is the minimum stock value, and unit/category relationships exist on `Product`.
- `product_prices.average_purchase_price` stores tenant-specific current average cost.
- `transactions` includes global and location previous/after quantity fields that support historical stock reconstruction.
- `InventoryValuationReportExport` already contains running average and transaction-date resolution logic that can be extracted or reused.

## Goals / Non-Goals

**Goals:**

- Add a real `Ringkasan persediaan barang` report under Reports > Produk.
- Compute product-level stock on hand, average cost, and value as of the selected date for the active setting.
- Aggregate stock across all locations, matching the Ringkasan sample and keeping warehouse-specific reports separate.
- Support sample-defined filters: date/period, stock status, categories with match mode, products, sort column, and sort direction.
- Preserve nullable product code output as blank rather than substituting `-`.
- Provide UI totals, pagination, CSV export, and XLSX export with deterministic parity.
- Keep implementation within existing Laravel, Livewire, Reports, Eloquent, permission, and export patterns.

**Non-Goals:**

- No warehouse selector for Ringkasan.
- No product/account mapping or enabled output for `Tampilkan akun persediaan barang` in this change.
- No schema migration unless implementation discovers a missing index that is required for acceptable performance.
- No change to existing `Valuasi Stok`, `Nilai persediaan barang`, `Detail persediaan barang`, `Kuantitas stok gudang`, or `Nilai stok gudang` behavior beyond shared-service extraction that preserves their current outputs.
- No rewrite of stock posting, product import, POS stock allocation, or product price synchronization behavior.

## Decisions

### Decision 1: Implement Ringkasan as an as-of transaction-derived summary

Ringkasan rows should be calculated from inventory transaction history up to the selected date. For each stock-managed product in the active setting, the report should derive ending stock on hand as of end-of-day and pair it with the average cost applicable to that same as-of calculation. `Nilai` is `stock_on_hand * average_cost`.

Rationale:

- The Ringkasan sample total matches the neighboring `Nilai persediaan barang` grand total for the same selected date.
- The detail and valuation samples use `Saldo Awal` and transaction history to derive inventory state.
- Current `product_stocks` is insufficient for historical as-of dates.

Alternatives considered:

- Use current `product_stocks` only and treat the selected date as metadata. Rejected because it cannot correctly answer historical as-of dates and conflicts with the report family behavior.
- Build Ringkasan from the existing `InventoryValuationReportExport` array output. Rejected as a direct dependency because that export is presentation-shaped, but its transaction and average-cost logic should be reused or extracted.

### Decision 2: Extract a reusable inventory valuation summary service

Create a report service under `app/Services/Reports` that accepts a filter DTO/array and returns stable row objects plus aggregate metadata. The service should own product selection, transaction loading, date resolution, stock replay, average-cost derivation, filtering, sorting, pagination totals, and total value. Livewire and exports should consume the same service.

Rationale:

- UI/export parity is easier when both surfaces share row mapping.
- Existing valuation logic is complex enough that duplicating it risks drift.
- Future inventory report work can reuse the same calculation boundary.

Alternatives considered:

- Put all logic in the Livewire component. Rejected because exports would need duplicate logic.
- Put all logic in the export. Rejected because the UI table also needs totals, pagination, and filters.

### Decision 3: Preserve Ringkasan as all-location aggregation

Do not add a `locationId` or warehouse selector to this report. Sum stock movements across locations for each product.

Rationale:

- The Ringkasan UI/filter sample has no warehouse selector.
- Warehouse-specific reports already exist as separate report concepts in the sample set.
- Adding location filtering would blur product summary and warehouse report responsibilities.

Alternatives considered:

- Add optional warehouse filtering as a convenience. Rejected for this proposal because it would deviate from the sample and overlap `Kuantitas stok gudang` / `Nilai stok gudang`.

### Decision 4: Treat product code as nullable display data

Display and export blank `Kode Produk` when `products.product_code` is null or empty.

Rationale:

- The schema allows nullable product codes.
- The Ringkasan sample contains blank codes for every row.
- Export parity with Mekari-style files is better served by blank values than placeholder dashes.

### Decision 5: Defer inventory account output

The UI sample includes `Tampilkan akun persediaan barang`, but no captured output shows the enabled columns, and the current product schema does not expose a reliable product-level inventory account mapping. The first implementation should not expose a functional account toggle unless implementation identifies an existing authoritative data source.

Rationale:

- Shipping a toggle with guessed account data would create accounting-report risk.
- Existing operational balance sheet code notes inventory valuation is derived from current stock/cost rather than double-entry ledger data, reinforcing caution around account output.

Alternatives considered:

- Show a generic `Persediaan Barang` account for every product. Rejected because it would imply precision the schema does not support.

### Decision 6: Match sample exports by file type

CSV should start directly at the table header row. XLSX should include report metadata rows above the table: company name, `Ringkasan Persediaan Barang`, selected date, currency note, sort metadata, blank spacer row, then table headers and rows, followed by total value.

Rationale:

- This exactly matches the Ringkasan sample file behavior.
- Existing report export specs in the project distinguish CSV and XLSX metadata behavior similarly.

## Risks / Trade-offs

- Historical average-cost reconstruction may not match product price snapshots if older transactions lack reliable unit cost. → Reuse the existing inventory valuation running-average behavior and add focused tests covering purchases, sales, adjustments, and opening stock.
- Transaction replay can be expensive with thousands of products and long histories. → Load only active-setting stock-managed products and necessary transactions, use chunking/collections carefully, and add indexes only if profiling shows they are required.
- UI count and export row count can drift if pagination/filtering uses a different mapper. → Use the shared service for both UI and exports and assert parity in tests.
- Product count in the captured UI says 4702 while CSV/XLSX contain 4701 product rows. → Define deterministic product row counting from the returned dataset rather than reproducing the captured mismatch.
- Negative stock may surprise users in status filters. → Treat negative stock as stock on hand, include it in totals, and define `stok tersedia` as `> 0`, `stok habis` as `<= 0`, and below-minimum as `< minimum`.
- Existing `InventoryValuationReportExport` may have presentation-specific assumptions. → Extract shared calculation only where behavior is proven equivalent; otherwise create a dedicated service and compare totals against existing report samples/tests.

## Migration Plan

1. Add the report route/controller/view and Livewire component behind `inventoryValuationReports.access`.
2. Add the inventory summary query/calculation service and row DTO/snapshot shape.
3. Add CSV/XLSX export classes using the shared service.
4. Update Reports landing card configuration to link `Ringkasan persediaan barang`.
5. Add feature/Livewire/export tests for permission, filters, as-of calculation, totals, sorting, pagination, nullable product code, negative stock, and export parity.
6. Rollback is removing the new route/component/export/service and restoring the card to placeholder state; no schema rollback is expected.

## Open Questions

- Should a future change implement `Tampilkan akun persediaan barang` by introducing product/account configuration, or should it map through a global inventory account setting if one exists?
- Should historical average-cost calculations include `INIT` transaction costs when such costs are available, or fall back to current `product_prices.average_purchase_price` for initialization-only products?
