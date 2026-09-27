## 1. Shared Report Data

- [x] 1.1 Inspect current amount storage and date/status conventions for `Sale`, `SaleReturn`, `Purchase`, `PurchaseReturn`, and `Expense`, including active `setting_id` scoping behavior
- [x] 1.2 Create an operational profit/loss report value object under `app/Services/Reports/` that exposes currency code, period label, sections, rows, subtotals, and final `Laba (Rugi)` total
- [x] 1.3 Create an operational profit/loss report service under `app/Services/Reports/` that queries completed sales, completed sale returns, completed purchases, completed purchase returns, and approved non-archived expenses for the selected date range
- [x] 1.4 Implement the agreed formula: net revenue = sales - sale returns; total cost = purchases - purchase returns + expenses; profit/loss = net revenue - total cost
- [x] 1.5 Ensure subtractive rows such as sale returns and purchase returns are represented consistently for display/export while preserving correct subtotal math
- [x] 1.5+ (Feedback) Fix returned sales/purchases: include RETURNED and RETURNED_PARTIALLY statuses in service queries to avoid dropping source transactions before returns are subtracted
- [x] 1.5++ (Feedback) Remove partial statuses: exclude DISPATCHED_PARTIALLY and RECEIVED_PARTIALLY from "completed" transaction set to avoid overstating incomplete orders

## 2. Livewire Report Component

- [x] 2.1 Refactor `App\Livewire\Reports\ProfitLossReport` to use the shared operational report service instead of maintaining separate card totals and payment totals
- [x] 2.2 Remove payment received, payment sent, and payment net calculations from the profit/loss component
- [x] 2.3 Preserve existing route, `reports.access` gate, filter action, export action, and filename behavior unless tests reveal an existing same-day date validation issue to correct
- [x] 2.4 Expose render-ready report data to the Blade view, including period label, currency code, sections, rows, subtotals, and final total

## 3. Prompt-Adapted UI

- [x] 3.1 Replace the current summary-card grid in `resources/views/livewire/reports/profit-loss-report.blade.php` with a Jurnal-style table adapted from `prompt.txt`
- [x] 3.2 Render the title `Laporan Laba Rugi`, currency subtitle `(dalam <currency>)`, date filters labeled `Tanggal awal` and `Tanggal akhir`, and existing filter/export actions
- [x] 3.3 Render the table period header and operational sections: `Pendapatan`, `Penjualan`, `Retur Penjualan`, `Total Pendapatan Bersih`, `Biaya`, `Pembelian`, `Retur Pembelian`, `Beban`, `Total Biaya`, and emphasized `Laba (Rugi)`
- [x] 3.4 Format subtractive and negative amounts with parentheses, using existing project currency/number formatting conventions where possible
- [x] 3.5 Confirm the UI does not render account codes, account drill-down links, `Beban Pokok Pendapatan`, `Laba Kotor`, `Laba Operasional`, or the old summary cards

## 4. Excel Export Parity

- [x] 4.1 Refactor `App\Exports\ProfitLossReportExport` to consume the same operational profit/loss report data as the screen
- [x] 4.2 Update exported rows and labels to match the operational table shown in the UI
- [x] 4.3 Remove ledger-only export behavior, including chart-of-account rows, account codes, `Beban Pokok Pendapatan`, `Laba Kotor`, and `Laba Operasional`
- [x] 4.4 Preserve the existing export entry point and generated filename pattern

## 5. Automated Verification

- [x] 5.1 Add focused tests for operational calculation totals across sales, sale returns, purchases, purchase returns, and approved non-archived expenses
- [x] 5.2 Add a date filtering test proving returns are included by return date and original transactions outside the selected period are not included
- [x] 5.3 Add UI assertions that the report renders the prompt-adapted title, currency subtitle, date labels, operational section labels, subtotal labels, and final `Laba (Rugi)` row
- [x] 5.4 Add UI assertions that old summary cards and payment metrics are absent
- [x] 5.5 Add export parity coverage proving Excel labels and key totals match the screen/report service for the same filters
- [x] 5.6 Add or preserve access tests proving `reports.access` can view `profit-loss-report.index` and users without it are denied
- [x] 5.6+ (Feedback) Add return-status regression tests: sales/purchases with RETURNED or RETURNED_PARTIALLY status are included in calculations while returns subtract correctly
- [x] 5.6++ (Feedback) Implement `reports.access` gate in ProfitLossReport Livewire component and add authorization tests
- [x] 5.6+++ (Feedback) Add partial-status exclusion tests: ensure DISPATCHED_PARTIALLY and RECEIVED_PARTIALLY documents are excluded (not counted as completed)

## 6. Final Checks

- [x] 6.1 Review the final structure (value object, isolated service, decoupled from specific tables).
- [x] 6.2 Audit that Livewire Component, blade file, and Export class call the service identically.
- [x] 6.3 Run all tests locally and ensure they pass.
- [x] 6.4 Review the rendered report manually against `prompt.txt` for header, filter area, table hierarchy, subtotal emphasis, and absence of accounting rows
- [x] 6.5 Review `git diff` to ensure no unrelated files or completed OpenSpec changes were modified
