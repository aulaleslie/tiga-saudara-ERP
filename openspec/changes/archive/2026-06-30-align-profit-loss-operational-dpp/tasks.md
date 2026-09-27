## 1. Report Calculation Tests

- [x] 1.1 Add focused service tests proving `Penjualan` uses `sale_details.sub_total - product_tax_amount` for scoped finalized sales.
- [x] 1.2 Add service coverage proving sale header `tax_amount` and `shipping_amount` are excluded from revenue.
- [x] 1.3 Add service coverage proving header/global `discount_amount` appears as a separate negative `Diskon Penjualan` row and line discounts are not subtracted again.
- [x] 1.4 Add service coverage proving `sale_returns` and `sale_return_details` do not affect revenue, HPP, or final profit/loss.
- [x] 1.5 Add service coverage proving HPP uses `cost_unit_snapshot * current quantity`, not `cost_total_snapshot`.
- [x] 1.6 Add service coverage proving null `cost_unit_snapshot` contributes zero and does not use current product average price.
- [x] 1.7 Add service coverage proving approved non-archived expenses remain gross, including tax.
- [x] 1.8 Add service coverage preserving selected setting scope and date range filtering.

## 2. Report Service Model

- [x] 2.1 Refactor `OperationalProfitLossReport` to expose section rows and subtotal rows shared by UI and export.
- [x] 2.2 Update `OperationalProfitLossReportService` to collect eligible sale IDs by selected settings, date range, and finalized statuses.
- [x] 2.3 Replace sales revenue aggregation with sale detail DPP aggregation.
- [x] 2.4 Add global/header discount aggregation as a separate negative report row.
- [x] 2.5 Remove sale return aggregation from the report calculation path.
- [x] 2.6 Replace HPP aggregation with `COALESCE(cost_unit_snapshot, 0) * sale_details.quantity`.
- [x] 2.7 Preserve gross approved expense aggregation using existing expense header amounts.
- [x] 2.8 Add sample-aligned operational subtotals for `Total dari Pendapatan`, `Laba Kotor`, `Total dari Beban Operasional`, `Laba Operasional`, `Total dari Pendapatan (Beban Lain-lain)`, and `Laba (Rugi)`.

## 3. UI and Export

- [x] 3.1 Update the Livewire Blade table to render the shared report sections and row styles without account codes or drill-down links.
- [x] 3.2 Update `ProfitLossReportExport` to consume the same shared report rows and totals as the screen.
- [x] 3.3 Ensure the report and export labels include `Penjualan`, `Diskon Penjualan`, `Beban Pokok Pendapatan`, `Beban Operasional`, and `Laba (Rugi)`.
- [x] 3.4 Ensure the report and export do not render `Retur Penjualan`, completed purchases, purchase returns, chart-of-account codes, or account drill-down links.

## 4. Integration Verification

- [x] 4.1 Add or update Livewire/report rendering tests for sample-aligned row presence and omitted return/accounting rows.
- [x] 4.2 Add or update export tests proving exported values match the service/screen totals for the same filters.
- [x] 4.3 Run the focused profit/loss report test suite.
- [x] 4.4 Run any broader report or Laravel test command needed to cover touched report/export behavior.
