## Context

The archived `2026-06-30-align-profit-loss-operational-dpp` change made Laporan Laba Rugi use the current sale document as its source of truth: revenue comes from sale detail DPP, global sale discount is shown separately, sale returns are not queried as report sources, HPP comes from `sale_details.cost_unit_snapshot * quantity`, and expenses remain gross.

The remaining operational financial reports still use older approximations:

- `OperationalMovementEventService` credits revenue from `sales.total_amount`, debits operational cost from `purchases.total_amount`, and creates sale return reversal rows from `sale_returns`.
- Buku Besar consumes those movement events directly.
- Neraca Saldo consumes the same movement events and maps them into trial-balance-style debit/credit rows.
- Neraca calculates receivables directly from sale header totals minus sale return totals and sale payments.
- Arus Kas is cash-basis and uses payment/refund/expense records rather than revenue or HPP.

This creates visible disagreement between reports for taxed sales, shipping, discounts, returns, and stocked product costing.

## Goals / Non-Goals

**Goals:**

- Make Buku Besar and Neraca Saldo use the same sales DPP and HPP basis as Laporan Laba Rugi.
- Treat the current sale document as authoritative for sales revenue and sales-cost reporting, including post-return corrected quantities.
- Avoid subtracting completed sale returns from revenue/HPP where that would double-count a return already reflected in sale details.
- Preserve payment movement reporting for cash, receivables, payables, sale return refunds, and purchase return refunds.
- Keep approved expenses gross, matching Laporan Laba Rugi.
- Keep Arus Kas cash-basis and clarify that it does not use non-cash DPP/HPP.
- Preserve report permissions, routes, filters, exports, and operational/non-COA positioning.

**Non-Goals:**

- No chart-of-account, journal, or double-entry accounting implementation.
- No new accounting ledger table or migration.
- No historical data repair for missing sale detail tax or cost snapshots.
- No change to sale, purchase, sale return, purchase return, payment, POS return, or expense lifecycle behavior.
- No attempt to make current inventory valuation historically accurate as of a prior date.
- No removal of cash/refund events from Arus Kas.

## Decisions

### D1: Change the shared movement event source for sales revenue

For eligible finalized sales, movement events SHALL calculate operational revenue from sale details:

```text
SUM(sale_details.sub_total - COALESCE(sale_details.product_tax_amount, 0))
```

Header `tax_amount` and `shipping_amount` SHALL NOT contribute to operational revenue events. Header/global `discount_amount` SHALL be represented as a revenue reduction event so Buku Besar and Neraca Saldo can reconcile with the Laba Rugi `Diskon Penjualan` row.

Rationale: this matches the Laba Rugi DPP decision and avoids header totals that mix tax, shipping, and discounts.

Alternative considered: continue using `sales.total_amount` for AR/revenue symmetry. Rejected because it is exactly the source of cross-report disagreement.

### D2: Add sales-cost/HPP movement from sale detail cost snapshots

For eligible finalized sale details, the shared movement source SHALL create operational cost/HPP movement from:

```text
SUM(COALESCE(sale_details.cost_unit_snapshot, 0) * sale_details.quantity)
```

This cost event is the operational HPP/Beban Pokok Penjualan basis for Buku Besar and Neraca Saldo. Missing unit snapshots contribute zero and MUST NOT be recalculated from current product cost.

Rationale: Laba Rugi already established this as the stable sale-time cost basis. It also remains aligned when a sale detail quantity is corrected after return processing.

Alternative considered: use `cost_total_snapshot`. Rejected because current quantity is the authoritative corrected sale basis and total snapshots may be stale.

### D3: Stop treating completed sale returns as revenue/HPP adjustment sources

The shared movement source SHALL NOT create revenue reversal or HPP reversal movement solely from completed `sale_returns` or `sale_return_details` when calculating sales revenue/cost buckets.

Sale return payment records SHALL remain cash/receivable movement sources, because they describe actual refund cash movement and settlement state.

Rationale: sale returns can double-reduce sales when the source sale document has already been corrected. Payments are different: they affect cash and receivable movement.

Alternative considered: classify mutating vs non-mutating sale returns. Rejected for this change because the agreed reporting basis is the current sale document, and introducing return classification would make reports depend on lifecycle details outside the current Laba Rugi rule.

### D4: Remove completed purchases from operational HPP/cost reporting, but keep payable/payment movement

Completed purchase headers SHALL NOT create `Beban Pokok Penjualan` movement. Purchase payments and purchase return payments SHALL remain cash/payable movement sources, and purchase headers may still create payable movement where needed for AP balances.

Rationale: buying inventory is not the same as recognizing HPP. HPP is recognized from sale detail cost snapshots under the operational Laba Rugi rule.

Alternative considered: keep purchases under a broad `Pembelian / Biaya Operasional` bucket. Rejected because it conflates inventory acquisition with sales cost and makes Neraca Saldo disagree with Laba Rugi.

### D5: Separate labels conceptually, even if implementation reuses buckets

The reporting model should distinguish these concepts:

```text
Sales DPP revenue       -> Pendapatan Operasional
Header/global discount  -> Pendapatan Operasional reduction
Sales cost snapshot     -> Beban Pokok Penjualan / Beban Pokok Pendapatan
Approved expense        -> Beban Operasional
Purchase/payment flows  -> AP/cash movement, not HPP
```

If implementation keeps existing bucket keys for compatibility, labels and descriptions SHOULD be updated so users do not read purchases as HPP. Tests should assert semantic totals rather than depending only on old labels like `Pembelian / Biaya Operasional`.

Rationale: the current single `OPERATIONAL_COST` bucket is too broad. A full bucket split is acceptable if low-risk, but preserving the key while improving semantics is also acceptable.

Alternative considered: create a full operational chart of accounts. Rejected as out of scope.

### D6: Align Neraca receivables with authoritative sale documents

Neraca customer receivables SHALL be based on eligible sale document amounts and payments as of the selected date without subtracting sale return totals again. If the sale document has been corrected after a return, that corrected sale amount is authoritative.

Rationale: subtracting `sale_returns.total_amount` can double-reduce receivables under the same rule that applies to revenue.

Alternative considered: keep existing gross sale minus sale return minus payment calculation. Rejected because it preserves the inconsistency this change is meant to remove.

### D7: Keep Arus Kas cash-basis

Arus Kas SHALL continue to use cash movement records: sale payments, purchase payments, sale return refunds, purchase return refunds, and approved expenses. It SHALL NOT add sale DPP revenue, HPP, or non-cash correction rows.

Rationale: cash flow answers a different question from profit/loss and balance reports. Forcing DPP/HPP into it would make it less correct.

Alternative considered: mirror Laba Rugi categories inside Arus Kas. Rejected because DPP/HPP are accrual-style operational measures, not cash movements.

## Risks / Trade-offs

- [Risk] Existing tests assert purchase totals as operational cost in Buku Besar/Neraca Saldo. -> Mitigation: update tests to assert sale-cost snapshot HPP and keep purchase tests focused on AP/cash movement.
- [Risk] Reports may no longer show a visible sale return row in Buku Besar/Neraca Saldo. -> Mitigation: document that revenue/HPP are sale-document based; keep refund payments visible as cash/AR movement.
- [Risk] Legacy sale details with missing tax or cost snapshots can understate/overstate DPP or HPP. -> Mitigation: keep null handling stable, do not invent recalculation, and add tests for null snapshot behavior.
- [Risk] Bucket labels may confuse users if old `Pembelian / Biaya Operasional` wording remains. -> Mitigation: update labels/descriptions or split rows where feasible, with export parity tests.
- [Risk] AR can appear different from old sale-return-subtracted calculations. -> Mitigation: test a returned/corrected sale scenario proving no double subtraction and a non-return scenario proving ordinary receivables still work.

## Migration Plan

1. Refactor or extend `OperationalMovementEventService` so sales revenue, discount, and HPP movement are derived from eligible sale details and sale headers using the Laba Rugi formulas.
2. Remove sale-return revenue/HPP reversal events from the shared movement source while preserving sale return payment cash/AR events.
3. Remove purchase header totals from operational HPP/cost movement while preserving purchase payable/payment and purchase return payment movement.
4. Adjust bucket labels/descriptions if needed to distinguish sales HPP from operational expenses and AP/purchase cash flows.
5. Update Neraca receivable calculation to use authoritative sale document totals and payments without subtracting sale return totals again.
6. Keep Arus Kas calculations payment-based; update only source notes or tests needed to clarify this basis.
7. Update XLSX/CSV/export parity where the reports expose exports.
8. Add focused tests for DPP revenue, global discount, HPP snapshot cost, ignored sale returns, purchase-not-HPP behavior, gross expenses, AR no-double-subtraction, and Arus Kas cash-basis preservation.

Rollback strategy: restore the previous movement event formulas and Neraca receivable formula. No schema rollback is required.

## Open Questions

- Should the visible bucket label `Pembelian / Biaya Operasional` be renamed in the same implementation to avoid implying purchases are HPP, or should this change keep labels stable and only adjust descriptions/totals?
