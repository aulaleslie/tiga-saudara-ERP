## Why

The current Laporan Laba Rugi screen is a dashboard of summary cards, while users expect a Mekari/Jurnal-style tabular report that is easier to audit for a selected period. The business does not maintain a full accounting ledger for this report, so the page should present an operational profit/loss view from sales, purchases, returns, and expenses rather than chart-of-account rows.

## What Changes

- Replace the current profit/loss summary-card UI with a Jurnal-style table adapted from `prompt.txt`: title, currency subtitle, date filter area, period column, grouped rows, subtotals, and final `Laba (Rugi)` row.
- Calculate the report operationally:
  - completed sales by sale date
  - completed sale returns by return date
  - completed purchases by purchase date
  - completed purchase returns by return date
  - approved, non-archived expenses by expense date
- Use existing transaction `total_amount` and expense `amount` values as-is; discounts, tax, and shipping remain represented inside those totals.
- Remove payment received, payment sent, and payment net from the profit/loss page because those are cashflow/payment metrics, not profit/loss inputs.
- Keep the existing route, permission, date filtering, and Excel export entry point, but make the screen and export agree on the same operational report rows and totals.

## Capabilities

### New Capabilities

- `operational-profit-loss-report`: A non-ledger Laporan Laba Rugi capability that presents operational revenue, cost, expense, and profit/loss totals using completed sales/purchases/returns and approved expenses in a Jurnal-style table layout.

### Modified Capabilities

<!-- None: existing reports-landing-navigation only governs the reports landing page card and route entry, not the downstream profit/loss report behavior. -->

## Impact

- Affected Livewire component: `app/Livewire/Reports/ProfitLossReport.php`.
- Affected Blade view: `resources/views/livewire/reports/profit-loss-report.blade.php`.
- Affected export: `app/Exports/ProfitLossReportExport.php`.
- Likely new shared report service/value object under `app/Services/Reports/` to keep UI and export totals aligned.
- Affected models/queries: `Sale`, `SaleReturn`, `Purchase`, `PurchaseReturn`, and `Expense`.
- No new database tables, permissions, routes, or external dependencies.
