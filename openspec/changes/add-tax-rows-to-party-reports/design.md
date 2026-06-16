## Context

`Pembelian Per Supplier` and `Penjualan Per Customer` are implemented as parallel Livewire reports backed by detail-row queries. The current purchase report reads `purchase_details.sub_total`, groups rows by supplier, and exports through `PurchaseBySupplierReportExport`. The sales report mirrors this using `sale_details.sub_total`, customer grouping, and `SaleByCustomerReportExport`.

Persisted detail rows also carry `product_tax_amount`. The with-tax purchase sample shows this amount as a separate `Pajak` report row immediately after the related product row. That shape is a report presentation concern: transaction persistence already decides whether tax exists, including PKP/non-PKP and POS split-owner cases. The reports should therefore use persisted amounts rather than recomputing or gating tax display from current setting state.

## Goals / Non-Goals

**Goals:**

- Represent persisted line tax amounts as separate `Pajak` rows in both party-grouped reports.
- Keep `product_tax_amount > 0` as the sole source of truth for tax-row expansion.
- Include tax rows in running totals, group subtotals, and grand totals.
- Preserve existing filter, sort, permission, snapshot guard, and detail-row pagination behavior.
- Remove `Keterangan` from Excel/CSV exports while retaining the on-screen column.

**Non-Goals:**

- No database schema changes.
- No changes to purchase, sale, POS, or tax persistence rules.
- No recomputation of tax from `tax_id`, tax rate, `settings.is_pkp`, or current tax configuration.
- No new report routes or permissions.
- No change to the existing wide `Daftar Pembelian` or `Daftar Penjualan` reports.

## Decisions

### Decision 1: Expand rows after the base detail query

Keep `PurchaseBySupplierReportQueryService::build()` and `SaleByCustomerReportQueryService::build()` rooted in real detail rows. Add a report-row mapping layer that can emit one product row and, when `product_tax_amount > 0`, one following `Pajak` row for the same transaction context.

Rationale:
- Existing filters, sorting, snapshot counts, and pagination are detail-row based.
- Tax rows are derived presentation rows, not independent database rows.
- This limits risk to report mapping/rendering/export logic.

Alternative considered: SQL `UNION ALL` product rows and tax rows. Rejected because it would complicate pagination counts, eager-loaded relationships, and existing tests while adding no persistence benefit.

### Decision 2: Persisted amount is authoritative

Display/export a `Pajak` row whenever `(float) product_tax_amount > 0`, regardless of `tax_id`, tax name, current `settings.is_pkp`, or current tax settings.

Rationale:
- Historical non-PKP behavior strips tax at write time, but POS split-owner behavior can persist taxable owner chunks even when current visible setting context differs.
- Reports should reflect the transaction as saved.
- Amount-based detection handles legacy or imported rows where `tax_id` may be absent but the monetary tax value exists.

Alternative considered: require `tax_id` to be present. Rejected because the user confirmed amount is the source of truth.

### Decision 3: Running totals operate on expanded report rows

For each detail, advance the running total by product `sub_total`, then advance it again by `product_tax_amount` if a tax row is emitted. Supplier/customer subtotals and grand totals follow the same expanded-row monetary sequence.

Rationale:
- This matches the reference sample where the product row total excludes tax, then the `Pajak` row total includes it.
- It keeps on-screen and export totals consistent.

Alternative considered: keep running totals based on detail subtotal only while showing tax rows. Rejected because the tax row would not reconcile to the group total.

### Decision 4: Preserve detail-row pagination

Continue paginating base detail results. A page with 15 details can render more than 15 table rows when some details have tax rows.

Rationale:
- Existing query count, page navigation, and continuation markers remain stable.
- Paginating by expanded rows would require custom pagination over derived rows and could split a product row from its tax row.

Alternative considered: paginate after expansion. Rejected because it is more complex and less predictable for users reconciling product/tax pairs.

### Decision 5: Export columns diverge from UI columns

Keep `Keterangan` in the browser table. Remove `Keterangan` from the XLSX/CSV heading and data arrays for purchase-by-supplier and sales-by-customer exports, then update XLSX merged ranges, numeric column formats, subtotal styling, and tests for the reduced column count.

Rationale:
- The user explicitly requested the column not be included in Excel or CSV.
- UI still benefits from notes/memos during interactive review.

Alternative considered: remove `Keterangan` everywhere. Rejected because the request was export-specific and the existing UI contract includes it.

## Risks / Trade-offs

- [Risk] Existing tests assert row counts based on real detail rows. → Mitigation: keep paginator counts detail-based and add explicit tests that rendered/exported row expansion adds tax rows only when amounts exist.
- [Risk] Export subtotal rows and XLSX formatting currently assume 10 columns. → Mitigation: update headings, row arrays, merged ranges, numeric formats, and style column references together.
- [Risk] Floating decimal comparisons can miss very small persisted amounts. → Mitigation: cast to float/decimal consistently and emit tax rows only when the amount is strictly greater than zero.
- [Risk] Sorting by supplier/customer total currently aggregates only `sub_total`. → Mitigation: include `product_tax_amount` in aggregate total ordering so total-based sort aligns with displayed totals.
- [Risk] Page two running totals currently pre-sum only prior-page `sub_total`. → Mitigation: prior-page carry-over must sum `sub_total + product_tax_amount` for each prior detail.

## Migration Plan

Deploy as a code-only change with focused report tests. No database migration or data backfill is required.

Rollback is a normal code revert. Existing persisted purchase/sale records are unchanged.

## Open Questions

None. The user confirmed `product_tax_amount` is the source of truth and confirmed the sales report is `Penjualan Per Customer`.
