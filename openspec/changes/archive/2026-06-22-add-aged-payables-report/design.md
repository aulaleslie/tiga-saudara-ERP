## Context

The Reports landing already contains an `Usia utang` card under the Pembelian tab, but it is a placeholder. The repository also has two nearby report implementations:

- `SupplierPayablesReport`, which lists outstanding purchase invoices grouped by supplier and computes as-of payable balances from active purchase payment rows.
- `AgedReceivablesReport`, which aggregates customer receivable balances into aging buckets and provides the closest Livewire/query/export pattern for the sampled aging shape.

The sample files in `report-sample/usia-utang/` define a vendor-level aged payable report titled `Hutang`, with columns `Vendor`, `Total`, `1 - 30 Hari`, `31 - 60 Hari`, `61 - 90 Hari`, and `> 90 Hari`. The sample UI exposes a `Per` as-of date, period presets, advanced filters for `Tanggal Transaksi` versus `Tanggal Jatuh Tempo`, tag all/any logic, sort by vendor or total, and XLSX/CSV/PDF exports. The sample CSV has a known upstream translation issue in its first header value, but the UI and XLSX identify the column as `Vendor`.

## Goals / Non-Goals

**Goals:**

- Expose `Usia utang` as a real report under Reports > Pembelian for users with `purchaseReports.access`.
- Add a vendor-level aged payables report that is separate from the existing invoice-detail `Utang supplier` report.
- Calculate outstanding payable balances as of a selected date from purchase invoices minus active purchase payments dated on or before that date.
- Bucket each purchase's remaining payable by either transaction date or due date, based on the selected aging basis.
- Match the sample's table shape, metadata vocabulary, grand total row, and export options.
- Preserve tenant scoping to the active `setting_id`.

**Non-Goals:**

- Do not change the existing `Utang supplier` invoice-detail report behavior.
- Do not add schema changes.
- Do not introduce new permissions.
- Do not implement supplier credits, purchase return credits, debit memos, or unapplied supplier balances as separate rows in v1.
- Do not recreate `/contacts/{id}` Mekari drill-down routes; use existing ERP routes only if practical.
- Do not preserve the sample CSV's translation-missing header bug.

## Decisions

### Create a separate aged payables report stack

Create `AgedPayablesReport` equivalents for the existing report architecture:

- `App\Livewire\Reports\AgedPayablesReport`
- `AgedPayablesReportFilterData`
- `AgedPayablesReportValidator`
- `AgedPayablesReportQueryService`
- `AgedPayablesReportSnapshot` and `AgedPayablesReportSnapshotService`
- `AgedPayablesReportExport`
- `AgedPayablesReportController`
- report index view and Livewire Blade view

Rationale: `SupplierPayablesReport` is invoice-detail oriented, while the sample is supplier summary plus aging buckets. A separate stack keeps both contracts simple and mirrors the existing receivable aging report.

Alternative considered: add an aging mode to `SupplierPayablesReport`. Rejected because the grouping, pagination, columns, totals, export metadata, and filters differ materially from the invoice-detail report.

### Use purchase invoices as the v1 data source

Build the query from `Modules\Purchase\Entities\Purchase`, joined to `suppliers` and left-joined to an active payment aggregate. Include only received or partially received purchase invoices already eligible for supplier payables reporting.

Rationale: The existing supplier payables report already uses purchases plus active payments as the payable read model. Reusing that basis avoids inventing new ledger semantics.

Alternative considered: build from general ledger payable accounts. Rejected for v1 because the sample is vendor-oriented and the current report code already has purchase/payment scoped behavior, tag support, and permissions.

### Compute historical payable balance from active payment rows

For each purchase, compute:

```text
ROUND(purchases.total_amount - COALESCE(active_payments.paid_to_date, 0), 2)
```

where `active_payments.paid_to_date` sums `purchase_payments.amount / 100.0` for `status = ACTIVE` and `date <= as_of_date`.

Rationale: `purchase_payments.amount` uses cents-like DB storage in existing reports. Using active payment rows allows back-dated as-of reporting and avoids mutable current `due_amount` drift.

Alternative considered: read `purchases.due_amount`. Rejected because it cannot answer historical as-of questions after later payments or invalidations.

### Support transaction-date and due-date aging basis

Add an `agingBasis` filter with values such as `transaction_date` and `due_date`. For transaction date, age by `purchases.date`; for due date, age by `purchases.due_date`. Buckets are:

- `0..30` days in `1 - 30 Hari`
- `31..60` days in `31 - 60 Hari`
- `61..90` days in `61 - 90 Hari`
- `>90` days in `> 90 Hari`

Rationale: The sample advanced filter explicitly offers both `Tanggal Transaksi` and `Tanggal Jatuh Tempo`. Same-day invoices must not fall through the first bucket, even though the label says `1 - 30 Hari`.

Alternative considered: implement only transaction-date aging, matching the default sample links. Rejected because the filter is visible in the sample and should be first-class in the spec.

### Aggregate per supplier in SQL and format at the edges

The query service should aggregate one row per supplier with conditional sums for each bucket and a total balance. The Livewire view and export class handle labels, IDR formatting, metadata rows, pagination, and grand totals.

Rationale: The sampled report is aggregate-oriented and could cover many invoices. SQL aggregation keeps memory use bounded and matches the existing aged receivables pattern.

Alternative considered: load eligible purchases and bucket in PHP. Rejected because it scales poorly and duplicates database grouping work.

### Snapshot exports use the last applied filters

Reuse the existing report snapshot pattern so exports are allowed only after a successful Filter action and only while pending filters still match the last applied filter state.

Rationale: This preserves recent report behavior that prevents users from exporting stale or unapplied filter combinations.

## Risks / Trade-offs

- **Purchase payment scaling mismatch** -> Reuse the supplier payables convention of dividing `purchase_payments.amount` by `100.0`, and cover this in query/export tests.
- **Due-date aging with null due dates** -> Treat null due dates conservatively by falling back to transaction date or by validating/documenting exclusion; implementation should choose one behavior and test it. Prefer fallback to transaction date to avoid hiding valid payables.
- **Floating precision residue in exports** -> Round balances to two decimals before positive filtering and export mapping; UI may format IDR as whole numbers while exports use numeric two-decimal formats.
- **Sample CSV translation bug** -> Use `Vendor` as the normalized header because both the UI and XLSX show `Vendor`; do not reproduce `translation missing: id.reports.aged-receivable.vendor-label`.
- **Credit/return incompleteness** -> Keep v1 purchase-invoice/payment based and document that supplier credits or returns require a later sample-backed allocation rule.
- **Large aggregate query cost** -> Filter by `setting_id`, purchase status, as-of date, positive rounded balance, optional supplier/tag filters, and keep report pagination at supplier aggregate row grain.

## Migration Plan

1. Add the aged payables controller, route, Livewire component, query/filter/validator/snapshot services, export class, and Blade views.
2. Update the Reports landing `Usia utang` card from placeholder to actionable route using `purchaseReports.access`.
3. Add focused tests for access, landing navigation, aging basis selection, bucket boundaries, payment cutoffs, tenant scoping, sorting, zero exclusion, totals, and exports.
4. Rollback is code-only: remove or disable the aged payables route/component/export stack and restore the `Usia utang` card to placeholder state.

## Open Questions

- Should bucket-cell drill-down later navigate to filtered `Utang supplier` invoice-detail rows?
- If supplier credits or purchase returns become in-scope, should they reduce the source invoice bucket, the oldest bucket, or appear as a separate adjustment bucket?
