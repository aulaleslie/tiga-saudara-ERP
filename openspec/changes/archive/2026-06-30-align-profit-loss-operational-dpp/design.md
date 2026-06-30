## Context

`/profit-loss-report` currently builds an operational Laporan Laba Rugi from selected settings and date filters, but revenue still comes from header totals and sale return totals. Header totals can include tax, shipping, and discounts, while the intended business rule is now narrower: the report should use the current corrected sale document as the source of truth.

The sale document is expected to already represent clean post-return quantities and values. Because of that, `sale_returns` must not be used as an additional report source; subtracting them can double-reduce revenue in return flows that already correct the original sale. The report still needs HPP from the historical average purchase price snapshot captured on the sale detail, and expenses remain gross operational expenses including tax.

The report should also move closer to the `report-sample/laporan-laba-rugi` shape by presenting operational sections and subtotals such as `Pendapatan`, `Beban Pokok Pendapatan`, `Laba Kotor`, `Beban Operasional`, `Laba Operasional`, `Pendapatan (Beban Lain-lain)`, and `Laba (Rugi)`, while intentionally omitting chart-of-account codes, account links, and accounting-ledger behavior.

## Goals / Non-Goals

**Goals:**

- Calculate `Penjualan` from sale detail DPP only.
- Exclude sale shipping from revenue.
- Show header/global sales discount as a separate negative `Diskon Penjualan` row.
- Ignore sale return tables in this report by design.
- Calculate HPP from `cost_unit_snapshot * current sale detail quantity`.
- Keep approved expense totals gross, including tax.
- Make the Livewire screen and export consume the same report structure and totals.
- Preserve existing company scope, date filters, access behavior, and currency/period labeling.

**Non-Goals:**

- No chart-of-account integration, account codes, ledger drill-down links, or journal posting behavior.
- No expense tax exclusion or expense DPP calculation.
- No separate sale return row or sale return revenue/HPP reversal in Laporan Laba Rugi.
- No change to sale or return lifecycle behavior.
- No new costing method beyond the existing sale detail average purchase price snapshot.
- No historical data repair beyond treating missing cost snapshots as zero for report stability.

## Decisions

### D1: Use current sale details as the revenue source

`Penjualan` will be calculated from eligible scoped sale details as:

```text
SUM(sale_details.sub_total - COALESCE(sale_details.product_tax_amount, 0))
```

The eligible sale headers remain scoped by selected settings, report date range, and finalized sale statuses used by the current report: `DISPATCHED`, `RETURNED PARTIALLY`, and `RETURNED`.

Rationale: sale detail `sub_total` is the durable commercial line value, and `product_tax_amount` is the durable tax component needed to report DPP. This avoids `sales.total_amount`, which can include shipping and tax.

Alternative considered: subtract `sales.tax_amount` from `sales.total_amount`. Rejected because header totals also include shipping and discounts, and line-level tax is safer across manual, POS, and imported sale flows.

### D2: Present only global/header discount as `Diskon Penjualan`

`Diskon Penjualan` will be calculated as the negative sum of eligible sale header `discount_amount` values.

Rationale: line-level product discounts are already reflected in sale detail `sub_total`. Only the global/header discount needs a separate visible row to match the requested report shape.

Alternative considered: include discount inside the `Penjualan` row. Rejected because the user wants global discount to have its own row.

### D3: Exclude shipping from revenue

Sale header `shipping_amount` will not appear in `Penjualan`, `Diskon Penjualan`, or any revenue subtotal.

Rationale: shipping is not part of sales DPP for this operational report. If shipping is represented as an approved expense elsewhere, it naturally remains in gross expenses; otherwise it is simply outside this report's revenue basis.

Alternative considered: show a shipping row under revenue or HPP. Rejected because the requested rule is to exclude shipping from sales.

### D4: Ignore sale returns in this report

The report will not query or subtract `sale_returns` or `sale_return_details`.

Rationale: the business rule is that the current sale document already represents clean sales after returns. Using sale return tables would double-count flows where the source sale has already been corrected.

Alternative considered: subtract only non-mutating returns. Rejected for this change because it makes return classification part of the report, while the chosen source-of-truth rule is simpler and explicit.

### D5: Calculate HPP from unit snapshot times current quantity

`Beban Pokok Pendapatan` will be calculated as:

```text
SUM(COALESCE(sale_details.cost_unit_snapshot, 0) * sale_details.quantity)
```

Rationale: `cost_unit_snapshot` is the average purchase price captured at sale time, while current sale detail quantity reflects the clean sale document after corrections. This keeps HPP aligned with current sale quantity even when a stored total snapshot is stale after return correction.

Alternative considered: sum `cost_total_snapshot`. Rejected because total snapshots may no longer match current sale detail quantity after operational corrections.

### D6: Keep expenses gross

`Beban Operasional` will use approved, non-archived expense header amounts exactly as stored by the expense module, including tax.

Rationale: the user explicitly wants expense to be calculated as the whole amount including tax.

Alternative considered: calculate expense DPP from expense details. Rejected because it conflicts with the requested expense basis.

### D7: Use a shared render/export row model

The report service/value object should expose section rows and subtotal rows that both the Livewire view and export consume.

Rationale: the existing screen/export have similar row logic. A shared structure reduces the risk that Excel and UI totals drift after adding sample-aligned sections and subtotals.

Alternative considered: update Blade and export separately. Rejected because duplicate financial row formulas are easy to diverge.

## Risks / Trade-offs

- [Risk] A return path that does not update the source sale document will no longer affect this report. -> Mitigation: document sale document state as the authoritative report source and add tests around the expected corrected sale-detail behavior.
- [Risk] Legacy sale details with missing `product_tax_amount` may overstate DPP if their `sub_total` includes tax. -> Mitigation: cover manual, POS, and import sale paths in tests and treat historical cleanup as a data-quality follow-up if needed.
- [Risk] Missing `cost_unit_snapshot` understates HPP. -> Mitigation: treat null as zero for report stability and include test coverage for the null behavior.
- [Risk] Bundle component costs may still depend on the existing snapshot behavior. -> Mitigation: keep this change scoped to the agreed HPP formula and add focused coverage if bundle rows already expose cost unit snapshots.
- [Risk] Sample-aligned labels may imply accounting behavior. -> Mitigation: omit account codes and drill-down links, and keep data sourced only from operational sale and expense tables.

## Migration Plan

1. Refactor the report service/value object to produce sample-aligned operational rows and totals.
2. Replace revenue formulas with sale detail DPP plus separate header discount.
3. Replace HPP formula with `cost_unit_snapshot * quantity`.
4. Remove sale return totals from the report calculation and output.
5. Keep gross approved expense calculation, selected setting scope, and date filters.
6. Update Livewire and export rendering to consume the shared report row structure.
7. Add focused tests for DPP sales, shipping exclusion, discount row, ignored returns, HPP unit snapshot quantity, gross expenses, and export/UI parity.

Rollback strategy: restore the previous report service/value formulas and rows. No migration or data rewrite is required.

## Open Questions

- None. The agreed rule is that the current sale document is authoritative for this report and expenses remain gross.
