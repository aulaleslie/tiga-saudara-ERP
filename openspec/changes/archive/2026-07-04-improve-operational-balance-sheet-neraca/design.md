## Context

The current `OperationalBalanceSheetReportService` builds a simplified operational Neraca from sales, purchases, payments, returns, expenses, and current product stock. The supplied `report-sample/neraca` files show a richer Jurnal-style layout with `Persediaan Barang`, `PPN Masukan`, `PPN Keluaran`, owner capital, retained earnings, current-period earnings, and CSV export.

The ERP already has nearby report infrastructure that should be reused:

- `WarehouseStockValuationReportQueryService` replays `transactions` by product and warehouse up to an as-of date and values stock with average purchase price.
- `OperationalProfitLossReportService` calculates operational profit/loss for a selected date range and business-source scope.
- Operational trial balance and cash flow reports already preserve the boundary that these reports are operational summaries, not complete accounting journal/COA ledgers.
- Existing report exports use Maatwebsite Excel for XLSX/CSV and several newer reports keep CSV plain while XLSX includes report metadata.

## Goals / Non-Goals

**Goals:**

- Make Neraca inventory as-of-date aware by reusing the established transaction replay semantics.
- Add sample-aligned equity rows for prior-year and current-year earnings using operational profit/loss formulas.
- Add CSV export parity for the filtered Neraca result.
- Improve tax row naming and supported tax presentation without inventing unsupported balances.
- Keep the report scoped to selected business sources and gated by `reports.access`.
- Preserve read-only behavior and avoid stock, product, payment, journal, or COA mutations.

**Non-Goals:**

- Do not convert Neraca into a full accounting balance sheet sourced from `journals` and `journal_items`.
- Do not add PDF export in this change.
- Do not implement account drill-down links or full account-depth/comparison-period filters.
- Do not change warehouse stock valuation, profit/loss, trial balance, cash flow, POS, Sales, Purchase, or return lifecycle behavior.

## Decisions

### Decision: Reuse warehouse stock valuation replay for `Persediaan Barang`

Neraca should obtain inventory value by invoking the same as-of stock movement semantics used by the warehouse stock valuation report, then summing `stock_value` for all active-scope warehouses and stock-managed products.

Rationale: this avoids a second stock replay implementation and fixes the current limitation where Neraca uses current `products.product_quantity * product_cost`.

Alternative considered: calculate inventory directly in `OperationalBalanceSheetReportService` from `transactions`. Rejected because it would duplicate transaction-date resolution, transfer handling, average-cost fallback behavior, and edge cases already covered by the warehouse valuation report.

### Decision: Calculate earnings rows through operational profit/loss service

`Pendapatan sampai Tahun lalu` should be operational profit/loss from the beginning of supported records through December 31 of the year before the selected as-of date. `Pendapatan Periode ini` should be operational profit/loss from January 1 of the selected year through the selected as-of date.

Rationale: this matches the sample's split between retained and current-period earnings while preserving the same operational DPP, sales discount, HPP snapshot, and approved-expense rules as Laporan Laba Rugi.

Alternative considered: derive earnings from trial balance operational buckets. Rejected for first implementation because profit/loss already encodes the intended operational income statement formulas and has setting-scope requirements.

### Decision: Keep a residual owner-capital row to balance the report

The equity section should include `Modal / Ekuitas`, `Pendapatan sampai Tahun lalu`, and `Pendapatan Periode ini`. `Modal / Ekuitas` is the balancing residual: total assets minus total liabilities minus the two earnings rows.

Rationale: the sample includes an explicit capital row such as `Modal CV`, but the current operational report has no reliable owner-capital posting source. A residual row keeps the balance sheet balanced while making earnings visible.

Alternative considered: read manual `journal_items` for capital balances. Rejected because existing operational reports intentionally do not use manual journal/COA balances, and mixing them into only one row would create a misleading hybrid ledger.

### Decision: Present supported tax rows with sample-aligned labels

Sales tax liabilities should be labeled as `PPN Keluaran` when operational sales tax exists. Purchase tax from eligible purchases may be surfaced as `PPN Masukan` under assets where supported by existing purchase tax data and selected as-of scope.

Rationale: this closes a visible sample gap while still tying values to supported operational documents.

Alternative considered: leave generic `Hutang Pajak`. Rejected because the sample and user feedback call for missing components to be scanned and filled where system data supports them.

### Decision: Add plain CSV export from the same report value object

CSV export should use the same filtered calculation output as screen and XLSX. CSV should be standards-compliant and spreadsheet-friendly, with no styled metadata rows unless the existing operational Neraca export structure requires a minimal header row.

Rationale: the sample has CSV in the export menu, and nearby reports already expose CSV with raw numeric values.

Alternative considered: add CSV by reusing the styled XLSX array exactly. Rejected because CSV consumers expect simple tabular data and existing report specs generally keep CSV plain.

## Risks / Trade-offs

- [Risk] Inventory value may differ from historical accounting valuation because average cost is current product price metadata, not a dated cost layer. -> Mitigation: reuse warehouse valuation behavior and update the source note to describe average-cost transaction replay rather than full accounting valuation.
- [Risk] Report totals can become confusing if residual capital is negative after showing earnings separately. -> Mitigation: label `Modal / Ekuitas` as operational residual and keep the source note explicit.
- [Risk] Profit/loss calls for prior-year earnings over a long historical range may be expensive. -> Mitigation: use focused aggregate queries already present in `OperationalProfitLossReportService`; add tests for representative periods and avoid loading row-level detail.
- [Risk] Multi-setting inventory valuation may need per-setting warehouse iteration because the warehouse valuation service currently centers on one setting. -> Mitigation: aggregate per selected setting and sum the resulting values.
- [Risk] CSV and XLSX can drift from screen rows. -> Mitigation: feed screen, XLSX, and CSV from one report result object and add export parity tests.
