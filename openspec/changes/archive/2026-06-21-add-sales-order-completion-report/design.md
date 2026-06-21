## Context

The Reports landing page already contains a `Penyelesaian pesanan penjualan` card under the Penjualan tab, but it is configured as a placeholder. The sample files in `report-sample/penyelesaian-pemesanan-penjualan/` define a Mekari-style sales order completion report with date filters, a `Mulai dari` selector, customer and tag filters, a summary table, and XLSX/CSV/PDF export options.

The local ERP does not use the Quotation module as the business equivalent for this report. Local Sales drafts are the quotation/offer stage, while Sales submitted for approval or beyond are the sales order stage. The report should therefore read from `sales`, not from `quotations`, and map:

```text
Penawaran -> sales.status = DRAFTED
Pemesanan -> sales.status in WAITING_APPROVAL, APPROVED, DISPATCHED PARTIALLY, DISPATCHED, RETURNED PARTIALLY, RETURNED
```

The existing Reports module already provides the implementation shape: route/controller wrapper under `Modules/Reports`, Livewire components under `app/Livewire/Reports`, report filter/query/snapshot services under `app/Services/Reports`, export classes under `app/Exports`, and feature tests under the Reports module. Existing sales reports already derive active paid amounts from active `sale_payments` with fallback to sale header amounts, and the sales delivery report already derives delivery amount from approved dispatches.

## Goals / Non-Goals

**Goals:**

- Add a real `Penyelesaian Pemesanan Penjualan` report under Reports > Penjualan.
- Use local Sales lifecycle semantics for `Penawaran` and `Pemesanan`.
- Match the sample's summary columns: order date, order number, order amount, order status, delivery amount, invoice amount, and payment amount.
- Use existing Sales, Dispatch, Payment, Tag, Customer, and Reports module data without schema changes.
- Preserve snapshot-gated export behavior so exports match the last applied filter state.
- Support CSV as a plain table and XLSX with report metadata plus a total row.
- Convert the existing Reports landing placeholder card into an actionable report route.

**Non-Goals:**

- No database migration or historical backfill.
- No new permission; reuse `saleReports.access`.
- No change to Sales, Dispatch, Payment, Quotation, POS, Sales Return, or approval lifecycle behavior.
- No implementation of a detailed `Order Completion Detail` template in this first change.
- No PDF export in the first implementation unless existing export infrastructure makes it low risk; the proposal/spec requires XLSX and CSV parity first.
- No use of the Quotation module as this report's `Penawaran` source.

## Decisions

### Use Sales status as the source-stage boundary

The `Mulai dari` filter will use `DRAFTED` Sales as `Penawaran` and submitted-or-later Sales as `Pemesanan`.

Rationale: `QuotationSalesController` copies quotation rows into the sale cart and passes a hidden `quotation_id`, but `StoreSaleRequest` does not validate it and `SaleService::createSale()` does not persist it. A report cannot reliably reconstruct quotation lineage from existing data. Users confirmed the intended domain model: sales draft is the quotation-like stage, and `Pemesanan` starts once the sale has left draft.

Alternative considered: query the Quotation module for `Penawaran`. Rejected because quotation-to-sale lineage is not persisted and would produce a report that cannot connect quotation rows to later order, delivery, invoice, and payment progress.

### Use approved dispatches for delivery amount

Delivery amount will be calculated from `dispatches` and `dispatch_details`, using only `dispatches.status = APPROVED`. The amount calculation should reuse the sales delivery report approach: aggregate delivered quantities by the existing composite dispatch key and multiply delivered quantity by the matching commercial unit amount from sale detail or bundle aggregates.

Rationale: Pending and rejected dispatches are not completed delivery. Existing delivery report logic already encodes the safest local interpretation.

Alternative considered: use `sales.status = DISPATCHED` to infer full delivery amount. Rejected because partial dispatches and approved dispatch detail rows carry the actual delivery quantities and timing.

### Use existing active-payment derivation for payment amount

Payment amount should use active `sale_payments` first, then fall back to `sales.paid_amount`, then `sales.total_amount - sales.due_amount` when payment rows are absent.

Rationale: `SaleReportQueryService` already uses this derivation to avoid counting invalidated payments while preserving imported/legacy rows that may only have header amounts.

Alternative considered: use only `sales.paid_amount`. Rejected because it can include stale amounts after payment invalidation and would diverge from hardened report behavior.

### Treat invoice amount as approved-or-later recognized sale amount

The codebase does not have a separate sales invoice table. For this report, `Invoice Amount` should be `sales.total_amount` when the selected sale is approved or later, and zero while the selected row is still `DRAFTED` or `WAITING_APPROVAL`.

Rationale: Sales are the local invoice/receivable document after approval. Existing receivable summary logic counts `APPROVED`, `DISPATCHED PARTIALLY`, and `DISPATCHED` as reportable sales. `WAITING_APPROVAL` is already a sales order stage for `Pemesanan`, but it is not yet approved/recognized.

Alternative considered: set invoice amount for all `Pemesanan` statuses including `WAITING_APPROVAL`. Rejected because waiting approval is submitted but not approved.

### Keep order status sample-facing but deterministic

The report should display a small status vocabulary aligned with the sample while deriving values from local lifecycle and payment state:

```text
Selesai       -> approved-or-later sale with no outstanding payment or returned/dispatched completion state as defined in the spec
Belum Dibayar -> no active/fallback payment amount
Terbayar Sebagian -> payment amount greater than zero and less than invoice amount
```

When a local lifecycle state is important and does not fit those labels, the implementation may expose the local status in tests/UI only if the spec is extended.

Rationale: The sample uses `Selesai` and `Belum Dibayar`, but local lifecycle labels are more granular. A deterministic mapping avoids leaking implementation status constants into the report's sample-facing contract.

## Risks / Trade-offs

- [Risk] `WAITING_APPROVAL` rows are included for `Pemesanan` but show zero invoice amount. -> Mitigation: test this explicitly so order-stage and invoice-recognition semantics stay separate.
- [Risk] Delivery amount can be zero for completed-looking sales when dispatch detail commercial matching is incomplete. -> Mitigation: left-join commercial aggregates like the sales delivery report and preserve rows with zero amount rather than fabricating totals.
- [Risk] Imported or legacy payments may not have active payment rows. -> Mitigation: reuse the existing payment fallback chain from `SaleReportQueryService`.
- [Risk] `RETURNED` and `RETURNED PARTIALLY` invoice/payment semantics can be nuanced. -> Mitigation: first implementation reports current sale header/payment state and does not allocate Sales Return adjustments beyond what existing sale state already reflects.
- [Risk] Users may expect the detail template from the sample. -> Mitigation: first scope is `Order Completion Summary`; leave detail template as future work.

## Migration Plan

No database migration is required.

Implementation can be deployed as application code:

1. Add the new report route, controller, wrapper view, Livewire component, services, export class, and Blade view.
2. Update the Reports landing card from placeholder to route-backed.
3. Add focused tests for access, landing navigation, filter mapping, amount derivation, snapshot exports, and export shapes.
4. Roll back by removing the route/component/service/export/view additions and restoring the landing card to placeholder state.

## Open Questions

- Should `RETURNED` and `RETURNED PARTIALLY` display their local return status in a later report mode, or remain summarized through the sample-facing status labels?
- Should PDF export and `Order Completion Detail` be added in a follow-up change for exact sample menu parity?
