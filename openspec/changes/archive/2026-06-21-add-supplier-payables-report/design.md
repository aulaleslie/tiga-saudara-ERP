## Context

The Reports landing already shows an `Utang supplier` card under Pembelian, but it is marked as a placeholder. The codebase also has an empty `SupplierPayablesReport` Livewire component and Blade view. The sample in `report-sample/utang` defines the expected report as Jurnal-style `Laporan Hutang Supplier`: purchase invoices grouped by supplier, with invoice date, transaction type, invoice number, due date, description, invoice total, remaining balance, supplier subtotals, grand totals, and XLSX/CSV/PDF exports.

The closest existing implementation pattern is `CustomerReceivablesReport`, not `PurchaseBySupplierReport`. Customer receivables already implements an as-of balance report grouped by party, computes balances from active payment rows, supports snapshot-guarded exports, and exposes a report card from the landing page. Purchase by supplier is product/detail-grain purchase spend and should not be reused as the data contract for payables.

## Goals / Non-Goals

**Goals:**

- Activate `Utang supplier` as a real report route gated by `purchaseReports.access`.
- Build a supplier payables report at invoice grain using `purchases`, `purchase_payments`, `suppliers`, and tags.
- Compute historical remaining payable balances as of the selected date from active purchase payments dated on or before the as-of date.
- Match the sample's UI/export column vocabulary and grouping behavior.
- Reuse the existing report architecture: filter data object, validator, query service, snapshot service, Livewire component, export class, controller, module route, report landing card, and focused feature tests.

**Non-Goals:**

- Supplier credits, purchase return credits, debit memos, and unapplied supplier balances are not separate v1 report rows.
- The report will not rewrite purchase, payment, return, or ledger data.
- The report will not replace `Pembelian Per Supplier`, `Daftar Pembelian`, or operational balance sheet payables.
- No new external dependencies are required.

## Decisions

### Use invoice-grain purchases as the report base

Build the query from `Modules\Purchase\Entities\Purchase`, joined to `suppliers` and left-joined to an active payment aggregate. Each listed row represents one purchase invoice with remaining payable balance greater than zero.

Alternative considered: reuse `PurchaseBySupplierReportQueryService`. That service is detail/product-grain and includes product, tax, discount, and running purchase totals; using it would produce the wrong row grain for `vendor_balance`.

### Compute payable balance from active payment rows

For each purchase, compute `saldo` as:

```text
ROUND(purchases.total_amount - COALESCE(active_payments.paid_to_date, 0), 2)
```

where `active_payments.paid_to_date` sums `purchase_payments.amount / 100.0` for `status = ACTIVE` and `date <= as_of_date`. The report must filter out rows whose computed `saldo` is not positive.

Alternative considered: use `purchases.due_amount`. That field reflects current mutable state and cannot answer a back-dated as-of report after later payments or invalidations.

### Keep v1 scoped to purchase invoices only

The sample CSV and XLSX only contain `Purchase Invoice` rows. Purchase return credits and supplier credit records have different semantics and no clear sample representation in this report. If those concepts later need to appear, they should be added with a separate sample-backed change.

Alternative considered: subtract completed purchase returns or emit credit rows. That would make the report broader than the provided sample and introduce legacy return scaling/settlement complexity into the first implementation.

### Mirror CustomerReceivablesReport architecture

Create supplier-side equivalents:

- `SupplierPayablesReportFilterData`
- `SupplierPayablesReportValidator`
- `SupplierPayablesReportQueryService`
- `SupplierPayablesReportSnapshot` and `SupplierPayablesReportSnapshotService`
- `SupplierPayablesReportExport`
- `App\Livewire\Reports\SupplierPayablesReport`
- `SupplierPayablesReportController`

This keeps export freshness checks, filter application, pagination, grouped rendering, and report tests consistent with existing report patterns.

### Use sample-aligned labels with existing route conventions

The page title should use `Laporan Hutang Supplier`; the landing card remains `Utang supplier`. The route should live under `reports/*`, e.g. `reports.supplier-payables.index`, and the card should become actionable with the existing `purchaseReports.access` permission.

## Risks / Trade-offs

- **Payment amount scaling mismatch** -> `purchase_payments.amount` is stored through an accessor as cents-like integer storage. Query services must divide DB aggregate values by `100.0`, matching existing purchase report logic.
- **Historical current-balance drift** -> Back-dated reports must not use `purchases.due_amount`; tests should cover a payment after the as-of date.
- **Placeholder card regression** -> Reports landing tests should assert `Utang supplier` no longer shows placeholder treatment and links to the supplier payables route.
- **Large supplier groups** -> Group sorting by total balance needs aggregate subqueries similar to customer receivables. Keep pagination at invoice-row grain and preserve supplier grouping on the current page.
- **Terminology mismatch** -> The sample uses English `Purchase Invoice` in exports and Indonesian page labels. Use sample terms for export values and Bahasa report labels in the UI.

## Migration Plan

No database migration is expected. Deploying the change should add read-only report code and replace the placeholder landing-card metadata with an actionable route.

Rollback is code-only: restore the landing card to placeholder treatment and remove/disable the supplier payables route and component.

## Open Questions

None for v1. Supplier credits and debit memo style rows remain a future extension pending a sample that shows how they should appear in `vendor_balance`.
