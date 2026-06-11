## Why

The "Laporan Penjualan" report (`SaleReport`) is an old, simple component — single-select customer/tag/status filters, no detail/header mode, no sortable columns, and exports that re-run filters instead of validating against a snapshot. The purchase side ("Daftar Pembelian", `PurchaseReport`) is far richer and is the established pattern. Sales reporting should reach the same parity so users get consistent filtering, sorting, and reliable exports across both sides.

## What Changes

- Rebuild `SaleReport` to mirror the `PurchaseReport` architecture, replacing the inline-query component with a dedicated service stack:
  - Detail/Header report mode toggle (per-line vs per-document), persisted across requests.
  - Multi-select, searchable customer and tag filters with display pills.
  - Multi-select document statuses (dispatched-family) and payment statuses.
  - Period presets (today/this week/this month/this year) and a date-basis selector.
  - Sortable columns appropriate to the active mode.
  - Snapshot-validated Excel/CSV export (refuse export if filters changed since last Filter click).
- Introduce sales report services mirroring the purchase ones: `SaleReportFilterData`, `SaleReportQueryService`, `SaleReportValidator`, `SaleReportSnapshot`, `SaleReportSnapshotService`; upgrade `SaleReportExport` to consume a built query + filter data.
- Keep the existing route (`reports.sale-report.index` / `.global`) and controller; swap the rendered component to the rebuilt one. Global mode preserved.
- **NON-OBVIOUS**: Sales use `STATUS_DISPATCHED` / `STATUS_DISPATCHED_PARTIALLY` where purchases use received statuses. There is no sales analog to `supplier_purchase_number`; use `reference` instead. Do not copy purchase-only columns verbatim.

## Capabilities

### New Capabilities
- `sales-list-report`: A sales report (Daftar Penjualan) at parity with the purchase report — detail/header modes, multi-select searchable filters, period presets, date basis, sortable columns, and snapshot-validated exports, in setting-scoped and global variants.

### Modified Capabilities
<!-- None: no existing spec governs the legacy sale report behavior. -->

## Impact

- New services: `app/Services/Reports/SaleReportFilterData.php`, `SaleReportQueryService.php`, `SaleReportValidator.php`, `SaleReportSnapshot.php`, `SaleReportSnapshotService.php`.
- Rewritten: `app/Livewire/Reports/SaleReport.php`, `resources/views/livewire/reports/sale-report.blade.php`, `app/Exports/SaleReportExport.php`.
- Controller/route: `Modules/Reports/Http/Controllers/SaleReportController.php` (unchanged signatures; passes `isGlobal`). Existing routes reused.
- Permission: reuses `saleReports.access` / `saleReports.global.access`; no new permission.
- Reads: `Modules\Sale\Entities\Sale`, `Modules\People\Entities\Customer` (`customer_name`), `Spatie\Tags\Tag`.
- Tests: mirror the purchase report suite — `SaleReportHardeningTest`, `SaleReportPerformanceTest`, `SaleReportExportParityTest`.
- **BREAKING**: the `SaleReport` Livewire public API (properties, `mount($customers,...)` signature) changes; any external reference to the old component contract is replaced.
