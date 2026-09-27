## Context

`profit-loss-report.index` currently renders `App\Livewire\Reports\ProfitLossReport`, whose Blade view is a grid of summary cards for sales, returns, purchases, expenses, and payment metrics. That presentation does not match the Jurnal-style sample in `prompt.txt`, where users expect a compact report page with a header, date filters, one period column, grouped rows, subtotals, and a final `Laba (Rugi)` row.

The application does have `ChartOfAccount`, `Journal`, and `JournalItem` entities, and the current `ProfitLossReportExport` already uses some ledger-style grouping. The user explicitly decided this report should not be accounting-ledger based. For this business, Laporan Laba Rugi is an operational period summary using completed sales, completed purchases, completed returns, and approved expenses.

Current implementation concerns:

- `ProfitLossReport.php` and `ProfitLossReportExport.php` calculate different shapes of data.
- The UI includes payment received/sent/net metrics, which mix cashflow into profit/loss.
- The export has Jurnal-like labels such as `Beban Pokok Pendapatan`, `Laba Kotor`, and `Laba Operasional`, which imply COGS/accounting semantics that are out of scope.
- The UI should adapt the visual shape of `prompt.txt` without copying Mekari account codes or account drill-down links.

## Goals / Non-Goals

**Goals:**

- Replace the profit/loss card dashboard with a Jurnal-style operational table.
- Use Indonesian operational labels: `Pendapatan`, `Penjualan`, `Retur Penjualan`, `Biaya`, `Pembelian`, `Retur Pembelian`, `Beban`, `Total Pendapatan Bersih`, `Total Biaya`, and `Laba (Rugi)`.
- Calculate totals from transactional modules only:
  - completed `Sale` rows by sale date
  - completed `SaleReturn` rows by return date
  - completed `Purchase` rows by purchase date
  - completed `PurchaseReturn` rows by return date
  - approved, non-archived `Expense` rows by expense date
- Make the screen and Excel export use the same operational rows and totals.
- Preserve the existing route, permission, Livewire entry point, date filter behavior, and export button.
- Format negative values in parentheses in the report display.

**Non-Goals:**

- No chart-of-account or journal-backed profit/loss behavior.
- No COGS/HPP calculation, inventory valuation, gross profit, or operating profit tiers.
- No cashflow/payment received/payment sent/payment net metrics on this report.
- No account drill-down links.
- No database schema changes, new permissions, or new routes.
- No historical data rewrite.

## Decisions

### D1: Use a shared operational report builder

Create a focused service/value object pair under `app/Services/Reports/`, for example `OperationalProfitLossReportService` and `OperationalProfitLossReport`, that accepts `start_date`, `end_date`, and current `setting_id` context, then returns render-ready sections, rows, totals, period label, and currency code.

The Livewire component and `ProfitLossReportExport` should consume this same report object.

Rationale: the current UI/export split is the biggest correctness risk. One provider makes export parity testable and prevents future drift.

Alternative considered: keep calculations inside `ProfitLossReport.php` and duplicate them in the export. Rejected because the two already diverge and the requested UI/export shape should be consistent.

### D2: Treat purchases as immediate operational cost

The formula is:

```text
net_revenue = completed_sales_total - completed_sale_returns_total
total_cost = completed_purchases_total - completed_purchase_returns_total + approved_expenses_total
profit_loss = net_revenue - total_cost
```

Rationale: the user confirmed purchases should count when completed in the selected period, not when inventory is sold. This is simpler and matches the stated non-accounting business need.

Alternative considered: calculate HPP/COGS from sold inventory cost. Rejected because it requires accounting/inventory valuation semantics the user explicitly does not want for this report.

### D3: Use transaction dates, including return dates

Each source table is filtered by its own transaction date field. A sale returned in June affects June, not the original sale month.

Rationale: this makes the report answer "what happened during this selected period" and avoids retroactively changing prior period results.

Alternative considered: allocate returns back to original transaction dates. Rejected because it complicates user expectations and behaves more like restated accounting periods.

### D4: Adapt `prompt.txt` visually, not semantically

The UI should copy the report shape from `prompt.txt`: title, `(dalam IDR)`, filter row, period column, section rows, indented detail rows, bold subtotals, final emphasized `Laba (Rugi)`, and parenthesized negatives. It should not copy account codes, account links, or ledger section names.

Rationale: the user wants a familiar Jurnal visual experience while using operational ERP data.

Alternative considered: mirror the prompt exactly with account rows and Jurnal labels. Rejected because there is no accounting requirement and those labels would misrepresent the calculation.

### D5: Remove payment metrics from this report

Payments received, payments sent, and payments net should no longer render on the profit/loss page.

Rationale: completed sales and purchases are included regardless of payment status. Mixing payment totals into this page would turn it into a cashflow hybrid.

Alternative considered: keep payment cards above or below the table. Rejected because it weakens the report's meaning and the user chose to exclude payments.

## Risks / Trade-offs

- [Risk] Users may expect purchases to behave like HPP/COGS because the page is called Laporan Laba Rugi. -> Mitigation: use operational labels (`Pembelian`, `Biaya`) and avoid `Beban Pokok Pendapatan`, `Laba Kotor`, and `Laba Operasional`.
- [Risk] Existing export consumers may expect the previous ledger-like Excel rows. -> Mitigation: this is an intentional report behavior change; update export parity tests and keep the filename/entry point stable.
- [Risk] Amount storage units differ across modules or legacy migrations. -> Mitigation: inspect existing model/report conventions during implementation and add tests using known totals for sales, purchases, returns, and expenses.
- [Risk] Date validation currently requires `start_date` before `end_date`, which may reject same-day reports. -> Mitigation: evaluate during implementation; if same-day reports are supported elsewhere, update validation and tests accordingly.
- [Risk] `setting_id` scoping may differ between modules. -> Mitigation: preserve current report scoping conventions and query only records belonging to the active setting where the model supports it.
- [Risk] Replacing all cards may remove a quick-glance summary some users liked. -> Mitigation: the table itself has only a few rows and exposes the same headline result more clearly.
