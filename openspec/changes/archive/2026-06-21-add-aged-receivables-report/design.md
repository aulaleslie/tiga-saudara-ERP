## Context

The Reports landing page already includes an `Usia piutang` card under the Penjualan tab, but the card is a placeholder. The repository also has an existing `Piutang pelanggan` report implemented through `CustomerReceivablesReport`, `CustomerReceivablesReportQueryService`, and `CustomerReceivablesReportExport`. That report is invoice-detail oriented: it lists outstanding sales invoices grouped by customer and computes as-of balances from active sale payments.

The sample files in `report-sample/usia-piutang/` define a different report shape: one row per customer with bucketed outstanding receivable totals as of a selected date. The sample UI and exports use columns `Customer`, `Total`, `1 - 30 Hari`, `31 - 60 Hari`, `61 - 90 Hari`, and `> 90 Hari`, with a subtotal row in the UI/XLSX presentation. The sample's drill-down URLs use `date_type=transaction_date`, so this design ages balances by the sale transaction date, not the due date.

## Goals / Non-Goals

**Goals:**

- Expose `Usia piutang` as a real report under Reports > Penjualan for users with `saleReports.access`.
- Add a customer-level aged receivables report that is separate from the existing invoice-detail `Piutang pelanggan` report.
- Calculate outstanding balances as of a selected date from `sales.total_amount` minus active `sale_payments.amount` dated on or before that date.
- Bucket each sale's remaining receivable by age in days from `sales.date` to the selected as-of date.
- Keep CSV exports plain and tabular while allowing XLSX/PDF exports to include report metadata and subtotals.
- Preserve tenant scoping to the active `setting_id`.

**Non-Goals:**

- Do not change the existing `Piutang pelanggan` invoice-detail report behavior.
- Do not add schema changes.
- Do not introduce new permissions.
- Do not allocate completed sales returns, credit memos, or return payments into aging buckets in this iteration.
- Do not implement transaction-detail drill-down pages beyond practical customer/report links unless already available through existing routes.

## Decisions

### Separate report stack instead of a mode inside `CustomerReceivablesReport`

Create an `AgedReceivablesReport` Livewire component, query service, filter data object, snapshot service, export class, controller, and Blade views rather than adding an aging mode to `CustomerReceivablesReport`.

Rationale: `Piutang pelanggan` is invoice-detail oriented and has columns, grouping, pagination, subtotals, and export shape that differ materially from the sample. A separate report keeps both contracts simpler and preserves existing behavior.

Alternative considered: add a summary/detail mode to the existing customer receivables report. Rejected because it would complicate snapshot/export behavior and make one component responsible for two report shapes.

### Age by sale transaction date

Use `DATEDIFF(as_of_date, sales.date)` to assign outstanding sale balances to buckets. Include age `0..30` in the first bucket to avoid dropping same-day invoices, even though the label remains `1 - 30 Hari` to match the sample.

Rationale: The sample bucket links explicitly use `date_type=transaction_date`; due date is not part of the sample report fields.

Alternative considered: age by `sales.due_date`. Rejected for this change because that would create a past-due aging report, not the sampled transaction-age report.

### Balance basis matches the existing customer receivables report

Compute remaining receivable as `ROUND(sales.total_amount - COALESCE(active_payments_to_date, 0), 2)` and exclude rows whose rounded remaining balance is not positive.

Rationale: This matches the existing `CustomerReceivablesReportQueryService` and gives deterministic as-of replay from the payment ledger instead of mutable `sales.due_amount`.

Alternative considered: subtract completed sale returns in the aging query. Deferred because correct return/credit allocation into age buckets requires a domain rule that the sample does not provide.

### Aggregate in SQL, format in presentation/export layers

The query service should aggregate per customer with conditional sums for each bucket and a total column. The Livewire component and export class should handle labels, formatting, metadata rows, and subtotal presentation.

Rationale: Aggregating in SQL avoids loading individual sales rows for every report run and follows the report-query-service pattern used elsewhere in the codebase.

Alternative considered: load eligible sales and bucket in PHP. Rejected because the report is naturally aggregate-oriented and could grow large.

### Snapshot exports use the last applied filters

Reuse the snapshot pattern from existing report components so exports are allowed only after a successful Filter action and use the applied filter state.

Rationale: Existing report hardening work prevents exports from accidentally using pending/unapplied filter changes.

## Risks / Trade-offs

- Sales returns are not included in the first aging calculation -> Document the limitation in tests/specs and keep the behavior aligned with the current customer receivables detail report until allocation rules are defined.
- Floating precision remnants can leak into buckets -> Round outstanding balances and bucket sums to two decimals in the query/export mapping and display IDR using whole-number formatting in the UI.
- Large datasets could produce slow conditional aggregate queries -> Filter by `setting_id`, `sales.date <= as_of`, positive rounded balance, and reuse indexed sale/payment columns where available; add focused query tests and keep pagination at the customer aggregate row level.
- Drill-down links may not match Mekari sample URLs -> Link to existing ERP routes only; do not invent `/contacts/{id}` compatibility unless a later requirement asks for it.

## Migration Plan

1. Add the new report controller, route, Livewire component, query/filter/snapshot services, export class, and Blade views.
2. Update the Reports landing card from placeholder to actionable route using `saleReports.access`.
3. Add focused tests for access, landing navigation, bucket boundaries, payment cutoffs, tenant scoping, sorting, zero exclusion, and exports.
4. Rollback by reverting the route/controller/component/export additions and restoring the `Usia piutang` card to placeholder state.

## Open Questions

- Should future aging iterations allocate completed sales returns and credit memos back to the oldest outstanding invoice buckets, the matching source invoice bucket, or a separate adjustment bucket?
- Should bucket-cell drill-down navigate to filtered `Piutang pelanggan` detail rows when route/query support is added?
