## Why

The current Laporan Laba Rugi calculates revenue from sale and return header totals, which can include tax, shipping, and separate return effects that no longer match the intended operational report basis. The report needs to use the corrected sale document as the source of truth, count sales revenue as DPP only, present global sales discount separately, and keep HPP aligned with the average purchase price snapshot captured on each sale detail.

## What Changes

- Calculate `Penjualan` from current sale detail DPP: `sale_details.sub_total - sale_details.product_tax_amount`.
- Exclude sale header shipping from revenue calculations.
- Present sale header/global discount as its own negative `Diskon Penjualan` row.
- Stop using `sale_returns` as a Laporan Laba Rugi source; returns are intentionally ignored because the current sale document is already the clean post-return document.
- Calculate HPP from the sale detail average purchase price snapshot multiplied by the current sale detail quantity: `cost_unit_snapshot * quantity`.
- Keep approved expenses as gross amounts, including tax.
- Adapt the report screen/export row structure to follow `report-sample/laporan-laba-rugi` operational sections and subtotals, without chart-of-account codes or accounting drill-down behavior.
- Preserve the existing selected company scope, date filters, access rules, and screen/export parity.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `profit-loss-report-setting-scope`: Change the report's revenue, sales discount, return handling, HPP, expense, and sample-aligned row requirements while preserving selected company scope behavior.

## Impact

- Affected service/value generation: `app/Services/Reports/OperationalProfitLossReportService.php` and `app/Services/Reports/OperationalProfitLossReport.php`.
- Affected UI/export: `resources/views/livewire/reports/profit-loss-report.blade.php` and `app/Exports/ProfitLossReportExport.php`.
- Affected data sources: `sales`, `sale_details`, approved non-archived `expenses`, and existing settings/company scope filters.
- Verification needs focused report service, Livewire/UI, and export coverage for DPP sales, shipping exclusion, discount row, ignored returns, HPP from cost unit snapshots, gross expenses, and selected setting scope.
