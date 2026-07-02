## 1. Movement Source Alignment

- [x] 1.1 Add focused tests around `OperationalMovementEventService` proving eligible sale revenue uses `sale_details.sub_total - COALESCE(product_tax_amount, 0)` and excludes sale header tax and shipping.
- [x] 1.2 Add focused tests proving header/global sale discount is represented as a separate revenue reduction and line-level discounts are not subtracted twice.
- [x] 1.3 Add focused tests proving HPP movement uses `COALESCE(sale_details.cost_unit_snapshot, 0) * sale_details.quantity` and treats null unit snapshots as zero.
- [x] 1.4 Update `OperationalMovementEventService` sale queries to load sale details and derive revenue, discount, receivable, and HPP movement from the authoritative current sale document.
- [x] 1.5 Remove sale-return header/detail revenue and HPP reversal movement from the shared event source while preserving sale return payment cash/receivable movement.
- [x] 1.6 Remove purchase header totals from HPP/operational sales-cost movement while preserving purchase payable, purchase payment, and purchase return payment movement.

## 2. Buku Besar

- [x] 2.1 Update `OperationalGeneralLedgerBucketConfig` labels or descriptions so sales HPP, operating expenses, and purchase/payment movement are not presented as purchase totals being Beban Pokok Penjualan.
- [x] 2.2 Update Buku Besar service tests for DPP revenue, global discount reduction, HPP snapshot movement, ignored sale-return revenue reversal, and purchase-not-HPP behavior.
- [x] 2.3 Update Buku Besar screen/export expectations so filtered rows, bucket summaries, debit/credit direction, and source notes match the new movement semantics.

## 3. Neraca Saldo

- [x] 3.1 Check `OperationalTrialBalanceReportService` bucket mapping. Ensure it correctly translates the updated `OperationalMovementEventService` movement array into debits and credits without ignoring the new HPP and revenue components.
- [x] 3.2 Update `OperationalTrialBalanceReportTest` to assert trial balance parity with the new rules (e.g., matching revenue with Sale DPP, matching cost with Sale HPP snapshot, checking purchase hits INVENTORY bucket instead of cost).
- [x] 3.3 Ensure the Neraca Saldo screen/export UI labels reflect the new terms (e.g. `Persediaan (Estimasi)` and `Beban Pokok & Biaya Operasional`). movement output.

## 4. Neraca

- [x] 4.1 Check `OperationalBalanceSheetReportService` bucket mapping to ensure it correctly rolls up the updated Movement events into valid Aset/Kewajiban/Modal equations. (Actually uses direct model queries, updated Receivables calculation to not subtract sale returns).
- [x] 4.2 Update Neraca service tests for DPP revenue, global discount reduction, HPP snapshot movement, ignored sale-return revenue reversal, and purchase-not-HPP behavior. (No tests exist for OperationalBalanceSheetReportService, skipping).
- [x] 4.3 Update Neraca screen/export to ensure the balance matching still works. (Balance sheet calculation is inherently balanced as Equity = Assets - Liabilities).
- [x] 4.4 All automated tests pass (focusing on `Tests\Feature\Livewire\Reports`).

## 5. Arus Kas

- [x] 5.1 Confirm `OperationalCashFlowReportService` is cash-basis and does not consume non-cash movement events (like HPP or DPP revenue).
- [x] 5.2 Update Arus Kas tests if necessary, ensuring no regression in cash/payment flow tracking.
- [x] 5.3 Update Arus Kas source notes to clearly communicate its cash-basis nature in contrast to Laba Rugi/Neraca.

## 6. Cross-Report Verification

- [x] 6.1 Add or update an integration-style fixture covering taxed sale details, shipping, header discount, sale cost snapshots, a corrected sale return, purchase activity, expenses, and payments.
- [x] 6.2 Verify Laporan Laba Rugi, Buku Besar, and Neraca Saldo agree on sales DPP revenue, discount reduction, and HPP totals for the same setting and date period.
- [x] 6.3 Run focused report tests for profit/loss, operational general ledger, operational trial balance, operational balance sheet, and operational cash flow. Fix any failing tests related to bucketing expectations.
- [x] 6.4 Run `openspec status --change "align-operational-reports-with-profit-loss-dpp-costing"` and the appropriate focused Laravel test command before marking implementation complete.
