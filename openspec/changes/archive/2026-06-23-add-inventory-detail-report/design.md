## Context

The Reports > Produk landing tab currently exposes `Detail persediaan barang` as a placeholder. The sample files under `report-sample/detail-persediaan-barang/` define a Mekari/Jurnal-style quantity ledger over a selected date range. The CSV is flat with columns `Kode Barang`, `Barang`, `Tanggal`, `Tipe Transaksi`, `No. Transaksi`, `Deskripsi`, `Mutasi`, `Stok di Tangan`, and `Unit`. The XLSX is grouped by product and includes company name, report title `Rincian Persediaan Barang`, selected date range, `(dalam IDR)`, detail rows, and per-product `Total Stok di Tangan` subtotal rows.

The codebase already has the adjacent `InventoryValuationReport` report and `InventoryReplaySupport` helper. That implementation solves most of the brownfield inventory mechanics: active setting scoping, product/category filters, business-date resolution for purchase/sale/transfer references, stable transaction ordering, delta calculation, opening-balance replay, grouped pagination, and CSV/XLSX export wiring. `Detail persediaan barang` is the quantity-only sibling of that valuation ledger.

Constraints: Laravel 10 + Livewire 3, nwidart `Modules/Reports`, Maatwebsite Excel exports, Bootstrap/CoreUI conventions, existing permissions, no schema changes, and no rewrite of historical `transactions`.

## Goals / Non-Goals

**Goals:**

- Add a real `Detail persediaan barang` report page under Reports > Produk.
- Reuse existing inventory replay/date/delta mechanics so the detail report agrees with existing summary, warehouse quantity, and valuation reports.
- Emit quantity-only grouped ledger rows: product header, `Saldo Awal`, in-range mutations, and final `Total Stok di Tangan`.
- Provide date-range/period, category all/any, and product filters matching the existing inventory report family.
- Provide CSV and XLSX exports that honor active filters and include all products across all pages.
- Update the Reports landing card from placeholder to actionable link.

**Non-Goals:**

- No monetary columns, WAC calculation display, subtotal value, or grand total value. Those belong to `Nilai persediaan barang`.
- No per-warehouse split. The sample has no warehouse filter or warehouse column; the report is company/setting-wide.
- No changes to the existing `InventoryValuationReport`, `InventorySummaryReport`, `WarehouseStockQuantityReport`, or stock mutation behavior except shared helper reuse that preserves current outputs.
- No database migrations or inventory snapshot tables.

## Decisions

### Decision: Build a separate detail report service that reuses `InventoryReplaySupport`

Create `InventoryDetailReportQueryService` with a public shape similar to `InventoryValuationReportQueryService`, but only track running quantity. It should load active-setting stock-managed products, apply category/product filters, load matching transactions, resolve transaction dates/references through `InventoryReplaySupport`, sort transactions with the shared comparator, and produce grouped rows.

Alternative considered: reuse `InventoryValuationReportQueryService` and strip value fields in the Livewire view/export. Rejected because the valuation service loads and computes purchase/sale prices and WAC that the detail report does not need, which adds cost and couples a quantity-only report to monetary behavior.

### Decision: Opening stock comes from pre-range replay

For each product, replay all resolvable transactions dated strictly before `tanggalAwal` into `runningStock` without emitting them. Emit one `Saldo Awal` row using that running stock. Then continue replaying transactions dated from `tanggalAwal` through `tanggalAkhir` inclusive, emitting rows after each applied delta.

This matches the sample: selected range `01/06/2026 - 21/06/2026` has opening rows dated `31/05/2026`. In implementation, the opening row can display the day before start date for export parity or the opening cutoff date consistently across UI/export; tests should pin the chosen behavior.

### Decision: Keep product groups together and paginate by product

Paginate by product group rather than raw rows so a product's header, opening row, in-range rows, and subtotal stay together. This follows the valuation report design and avoids splitting a product's running-stock context across pages.

### Decision: Export CSV and XLSX use different layouts

CSV should be flat and include columns in the sample order:

`Kode Barang, Barang, Tanggal, Tipe Transaksi, No. Transaksi, Deskripsi, Mutasi, Stok di Tangan, Unit`

XLSX should be grouped:

- Row 1 company name.
- Row 2 `Rincian Persediaan Barang`.
- Row 3 selected date range.
- Row 4 `(dalam IDR)` to match the sample, even though the report is quantity-only.
- Row 6 table headers: `Tanggal`, `Tipe Transaksi`, `No. Transaksi`, `Deskripsi`, `Mutasi`, `Stok di Tangan`, `Unit`.
- Product header rows in the form `(<code>) | <name>`; if code is blank, `() | <name>`.
- Detail rows and a per-product subtotal row labeled `(<code> | <name>) Total Stok di Tangan`.

Alternative considered: make XLSX use the same flat shape as CSV. Rejected because the provided XLSX sample clearly demonstrates grouped formatting and report metadata.

### Decision: Use `stockMutationReports.access`

The existing placeholder is already gated by `stockMutationReports.access`, and this report is quantity/mutation-oriented rather than valuation-oriented. Keep that permission for the route, Livewire actions, exports, and Reports landing card.

## Risks / Trade-offs

- **[Date mismatch with source documents]** Using `transactions.created_at` alone can place purchase/sale movements on the wrong report date. -> Reuse `InventoryReplaySupport::resolveTransactionDate` and its purchase/sale/transfer metadata maps.
- **[Unresolvable transactions disappear silently]** Transactions without a resolvable date cannot be safely placed in the ledger. -> Follow existing report behavior and skip them, with focused tests for resolvable purchase, sale, adjustment, and transfer examples.
- **[Large catalogs are expensive]** The sample has about 4,941 product groups and 9,731 exported rows. -> Load transactions in bulk for filtered product IDs, group in memory like existing inventory reports, and paginate by product after computing the filtered result.
- **[CSV/XLSX drift]** Different export layouts can diverge. -> Use one query result structure and separate mapping methods for CSV and XLSX only at the final export layer.
- **[Blank product codes]** The sample has blank `Kode Barang` values. -> Treat product code as optional in UI/export and never use it as the grouping key.
