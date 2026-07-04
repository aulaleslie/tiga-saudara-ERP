## Why

The current operational Neraca omits several rows shown in the supplied `report-sample/neraca` files and values inventory from current product stock instead of the selected as-of date. Users need the report to be closer to the sample so stock, retained/current earnings, and CSV export are available for review and reconciliation.

## What Changes

- Calculate `Persediaan Barang` from transaction-replayed stock as of the selected date, reusing the warehouse valuation semantics already present in the system.
- Replace the single catch-all equity row with clearer owner-capital presentation that includes `Pendapatan sampai Tahun lalu` and `Pendapatan Periode ini`.
- Preserve the operational-report boundary: source values remain derived from supported operational documents, not complete accounting journal/COA balances.
- Add CSV export for Neraca using the same filtered calculation output as the screen and XLSX export.
- Add sample-aligned row labels for tax and equity where operational data can support them, while keeping unsupported items explicit rather than inventing balances.
- Update tests around as-of inventory, earnings split, totals, business-source scope, and export parity.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `operational-balance-sheet-report`: Improve Neraca calculation and export requirements for as-of inventory valuation, prior/current earnings rows, tax/equity presentation, and CSV export.

## Impact

- Report service: `app/Services/Reports/OperationalBalanceSheetReportService.php` and related value objects/row structures.
- Inventory source integration: reuse existing `transactions` replay and warehouse stock valuation support rather than current `products.product_quantity * product_cost`.
- Profit/loss source integration: reuse operational profit/loss formulas for prior-year and current-year earnings rows.
- Livewire UI: `app/Livewire/Reports/OperationalBalanceSheetReport.php` and `resources/views/livewire/reports/operational-balance-sheet-report.blade.php`.
- Export: `app/Exports/OperationalBalanceSheetReportExport.php` gains CSV-compatible output behavior and Livewire CSV download.
- Tests: focused service, Livewire, and export tests under `Modules/Reports/Tests/Feature`.
