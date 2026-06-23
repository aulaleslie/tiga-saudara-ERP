## 1. Report Wiring

- [x] 1.1 Add `InventoryDetailReportController` under `Modules/Reports/Http/Controllers` with `stockMutationReports.access` authorization.
- [x] 1.2 Register `reports.inventory-detail-report.index` in `Modules/Reports/Routes/web.php` under the authenticated reports route group.
- [x] 1.3 Add the `reports::inventory-detail-report.index` Blade entry view that mounts the Livewire report component.
- [x] 1.4 Update the Reports landing Produk card for `Detail persediaan barang` from placeholder to route-backed actionable card gated by `stockMutationReports.access`.

## 2. Query Service and Filter Data

- [x] 2.1 Create `InventoryDetailReportFilterData` with date range, category IDs, category match mode, product IDs, sort column, and sort direction parsing from arrays/requests.
- [x] 2.2 Create `InventoryDetailReportQueryService` that loads active-setting stock-managed products, applies product/category filters, and bulk-loads matching inventory transactions.
- [x] 2.3 Reuse `InventoryReplaySupport` to resolve transaction references, business dates, transfer metadata, signed deltas, and stable ordering.
- [x] 2.4 Replay pre-range transactions into per-product opening stock, emit a `Saldo Awal` row, then emit in-range ledger rows with running stock.
- [x] 2.5 Return grouped rows with product metadata, opening row, ledger rows, subtotal stock/unit, allRows for export, and product-group pagination.

## 3. Livewire Report UI

- [x] 3.1 Create `App\Livewire\Reports\InventoryDetailReport` with permission checks, default period/date state, apply/reset/cancel filter behavior, and Bootstrap pagination.
- [x] 3.2 Add category and product searchable multi-select behavior with `Mencakup semua` and `Salah satu` category match modes.
- [x] 3.3 Create `resources/views/livewire/reports/inventory-detail-report.blade.php` with title/currency note, date filters, drawer filters, export actions, loading/empty states, and grouped table rows.
- [x] 3.4 Render table columns as `Tanggal`, `Tipe transaksi`, `No. transaksi`, `Deskripsi`, `Mutasi`, `Stok di gudang`, and `Unit`, with per-product `Total Stok di Tangan` subtotal rows.

## 4. Exports

- [x] 4.1 Create `App\Exports\InventoryDetailReportExport` implementing `FromView`, `WithStyles`, and `ShouldAutoSize`.
- [x] 4.2 Transform `allRows` into an export array format: Title rows, Filter parameters, Table Headings (`Kode Barang`, `Barang`, `Tanggal`, `Tipe Transaksi`, `No. Transaksi`, `Deskripsi`, `Mutasi`, `Stok di Tangan`, `Unit`).
- [x] 4.3 Output each product group header, opening row, ledger rows, and a subtotal row (`Total stok di gudang`).
- [x] 4.4 Wire Livewire `exportExcel` and `exportCsv` actions to export all filtered rows across all pages and preserve multiline descriptions.

## 5. Tests and Verification

- [x] 5.1 Add access/route tests proving permitted users can open the report and users without `stockMutationReports.access` are denied.
- [x] 5.2 Add Reports landing tests proving `Detail persediaan barang` is actionable, uses `reports.inventory-detail-report.index`, and is hidden/denied according to permission.
- [x] 5.3 Add query service tests for opening stock from prior activity, purchase increases, sale/dispatch decreases, stock adjustment deltas, stable ordering, blank product codes, and grouped pagination.
- [x] 5.4 Add Livewire tests for date filters, category/product filters, reset/cancel/apply behavior, and rendered quantity-only columns/subtotals.
- [x] 5.5 Add export tests for CSV column order, XLSX grouped metadata/layout, all-pages export behavior, active filter honoring, and multiline description preservation.
- [x] 5.6 Run focused PHP tests for the new report and affected Reports landing behavior.
