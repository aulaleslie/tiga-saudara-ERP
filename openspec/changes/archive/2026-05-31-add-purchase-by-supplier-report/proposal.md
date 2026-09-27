## Why

Purchasing users need a supplier-grouped purchase report that mirrors the provided `Pembelian Per Supplier` sample, so they can review purchase activity and supplier subtotals without exporting or manually grouping `Daftar Pembelian` rows.

## What Changes

- Add a new `Pembelian Per Supplier` report under `Laporan -> Pembelian`.
- Reuse the existing `purchaseReports.access` permission and keep the report scoped to the active setting unless a future global variant is introduced.
- Render `Faktur pembelian` detail rows grouped by supplier, hiding suppliers without matching purchases.
- Default `Tanggal awal` and `Tanggal akhir` to the current calendar month.
- Provide sample-aligned view filters for date range, period preset, supplier, tag, product category, tag/category matching logic, and sorting.
- Show running `Total nominal tagihan` per supplier using `purchase_details.sub_total` as the line amount source.
- Do not add export behavior in this change; the scope is view-only.
- Do not add database schema changes.

## Capabilities

### New Capabilities
- `purchase-by-supplier-report`: Supplier-grouped purchase detail report for `Faktur pembelian`, including menu access, filters, grouping, running totals, and view-only behavior.

### Modified Capabilities
- None.

## Impact

- New report route/controller/view under the existing Reports module.
- New Livewire report component and report query/filter services following existing `App\Livewire\Reports` and `App\Services\Reports` patterns.
- Sidebar menu update under `Laporan -> Pembelian`.
- Focused feature tests for authorization, default filters, filter semantics, grouping, sorting, and rendered totals.
