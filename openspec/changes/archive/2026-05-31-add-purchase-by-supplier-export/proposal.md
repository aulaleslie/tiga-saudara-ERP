## Why

The `Pembelian Per Supplier` report currently displays data but offers no way to export it. Users need to extract purchase-by-supplier data into spreadsheets for accounting, reconciliation, and external reporting — the same capability already present on the `Laporan Pembelian` report.

## What Changes

- Add **Excel (.xlsx) export** action to the `Pembelian Per Supplier` report, using the current applied filters and sort, producing a formatted spreadsheet with title and period header rows.
- Add **CSV export** action to the `Pembelian Per Supplier` report, using the same filters and sort, producing a flat CSV with column headers only.
- Both exports use **split columns**: `Supplier` and `Tanggal` as separate columns (11 columns total), matching the reference sample file `purchases_by_vendor_*.csv/xlsx`.
- Running totals in exports are computed **per-supplier in PHP** during row mapping, accumulating `sub_total` in order of the applied sort.
- A **session-based snapshot guard** prevents exporting stale data — export is blocked if applied filters have changed since the last `Filter` action.
- The existing `it_does_not_have_export_buttons` test requirement is **replaced**: the spec now requires export buttons to be present.

## Capabilities

### New Capabilities
- `purchase-by-supplier-report-export`: Export of the purchase-by-supplier report as XLSX and CSV, with snapshot guard, running total computation, and split Supplier/Tanggal columns.

### Modified Capabilities
- `purchase-by-supplier-report`: The "view-only, no export" requirement is replaced — the report now provides active Excel and CSV export actions.

## Impact

- **New files**: `app/Exports/PurchaseBySupplierReportExport.php`, `app/Services/Reports/PurchaseBySupplierReportSnapshot.php`, `app/Services/Reports/PurchaseBySupplierReportSnapshotService.php`
- **Modified files**: `app/Livewire/Reports/PurchaseBySupplierReport.php`, `app/Services/Reports/PurchaseBySupplierReportQueryService.php` (add `mapRowForExport()`), `app/Services/Reports/PurchaseBySupplierReportFilterData.php` (add `hash()`, `toArray()`, `fromArray()`), `resources/views/livewire/reports/purchase-by-supplier-report.blade.php` (add export dropdown)
- **Test changes**: `Modules/Reports/Tests/Feature/PurchaseBySupplierReportTest.php` (flip export button test); new export parity tests alongside
- **Dependencies**: `maatwebsite/excel` (already installed), `PhpOffice/PhpSpreadsheet` (transitive, already present)
