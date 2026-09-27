## Why

The `Daftar Pembelian` report already presents the purchase detail rows users need, but the export dropdown remains a disabled shell. Users need Excel and CSV files that match the report they filtered and sorted on screen so the report can be used outside the ERP.

## What Changes

- Enable Excel and CSV export actions from the existing `Ekspor` dropdown on `/reports/purchase-report`.
- Keep PDF unavailable for this change.
- Export all rows matching the last successfully applied filters, not only the current paginated page.
- Export rows using the current table sort selected by the user.
- Export columns matching the current `Daftar Pembelian` table column contract.
- Export raw numeric values for numeric and percentage columns while using `-` for empty optional values.
- Generate CSV as plain headers plus data rows.
- Generate XLSX with report metadata rows above the table.
- Use sample-style filenames such as `purchases_list_01-05-2026_31-05-2026.xlsx` and `.csv`.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `purchase-list-report`: Add functional Excel and CSV export requirements for the existing detail-line purchase list report.

## Impact

- Affects `App\Livewire\Reports\PurchaseReport` export actions and dropdown wiring.
- Affects or replaces `App\Exports\PurchaseReportExport`, which currently assumes stale purchase-header rows and does not match the current detail-row report.
- Reuses `App\Services\Reports\PurchaseReportQueryService`, `PurchaseReportFilterData`, `PurchaseReportValidator`, and `PurchaseReportSnapshotService`.
- Updates purchase report export tests that currently assert export is blocked.
- No new route, permission, database schema, or external dependency is expected.
