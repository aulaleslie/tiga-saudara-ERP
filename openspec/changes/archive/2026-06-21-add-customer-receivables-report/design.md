## Context

The Reports module already ships a family of grouped, filterable, exportable reports. The closest structural analog is the **Sale by Customer** report, whose stack lives in the app namespace (`app/Livewire/Reports/`, `app/Services/Reports/`) with a thin module controller and a `reports::` view. That report establishes the pattern this change copies: a `FilterData` DTO (with a hash for export gating), a `Validator`, a `QueryService` (build + sort + row mapping), and a `Snapshot` + `SnapshotService` pair that gate exports to the last-generated result.

The data already exists with no schema change:
- `sales` holds `date`, `due_date`, `reference`, `total_amount`, `customer_id`, `note`, and tenant `setting_id`.
- `sale_payments` is a dated settlement ledger: every cash payment, credit-memo application, and reversal flows through it. Rows carry `date`, `amount`, and `status` (`ACTIVE` / `INVALIDATED`). Reversals are status flips, not deletes. Credit-memo applications attach to a (dated) parent `sale_payment` via `sale_payment_id`, so the parent payment's date governs them.
- `sales.due_amount` / `sales.paid_amount` are **mutable running balances** maintained incrementally at write-time across several controllers; they reflect only the current instant and carry no history.

## Goals / Non-Goals

**Goals:**
- A working "Piutang pelanggan" report grouped by customer, as-of a chosen date, matching the Jurnal `customer_balance` sample (columns, subtotals).
- Truthful "Per <tanggal>" semantics: a back-dated cutoff shows the balance as it stood then.
- Filters and exports consistent with the existing Sale by Customer report.
- No schema changes; read-only against existing tables.

**Non-Goals:**
- Aging buckets (30/60/90/>90) — that is the separate "Usia piutang" card and remains a placeholder.
- Standalone unapplied customer-credit / credit-memo balances as their own rows. The sample CSV does not include them; only invoice-level outstanding balances are shown. (Noted as an open question.)
- Audit-grade "active on the as-of date" reconstruction of payments invalidated *after* the cutoff (see Risks).
- Any change to how balances are written elsewhere in the app.

## Decisions

### Decision: Compute remaining balance by replaying `sale_payments`, not by reading `sales.due_amount`
The label is "Per <tanggal>" and the card promises balances "pada tanggal tertentu". `sales.due_amount` is a current-only running total, so reading it would make a back-dated cutoff silently wrong. Instead:

```
sisa_piutang(invoice, asOf) =
    sales.total_amount
  − Σ sale_payments.amount  WHERE status = 'ACTIVE' AND date <= :asOf
keep invoices where the result > 0 AND sales.date <= :asOf
```

Implemented as a `leftJoinSub` summing active payments per `sale_id` with the `date <= :asOf` predicate, mirroring the subquery-join style already used in `SaleByCustomerReportQueryService::applySort`.

**Alternative considered — read `due_amount` directly (Tier A):** trivial (one query, no join) and produces identical output when as-of = today (which is how the sample was generated). Rejected as the default because it breaks the report's stated contract for any historical cutoff and the sample never exercises that case. The ledger is clean and dated, so the correct approach costs little.

### Decision: Invoice (sale) grain, not line-item grain
Unlike Sale by Customer (which operates on `sale_details`), receivables are per-invoice. One row per outstanding `sale`. This is simpler — no per-line tax/discount reconstruction, no running product totals.

### Decision: Reuse the Sale by Customer service-stack shape
Create `CustomerReceivablesReport{FilterData,Validator,QueryService,Snapshot,SnapshotService}` in `app/Services/Reports/`, a `CustomerReceivablesReport` Livewire component in `app/Livewire/Reports/`, a `CustomerReceivablesReportController` in the Reports module, and a `reports::customer-receivables.index` view. This keeps the new report idiomatic with its siblings and lets export-parity gating be copied wholesale (filter `hash()` compared against the snapshot).

**Alternative considered — fold into the Sale by Customer report.** Rejected: different grain, different columns, different balance semantics; sharing would entangle two reports.

### Decision: Grouping and subtotals computed in PHP after an ordered query
Order rows so a customer's invoices are contiguous (sort key applied at the customer-group level via a joined per-customer aggregate, exactly as Sale by Customer does for date/total sorts), then fold into customer groups in PHP and emit subtotal rows. The two sort modes — `customer_name` and `total_balance` — map to the two options in the sample's filter modal ("Pelanggan", "Total Sisa Piutang").

### Decision: Wire the existing placeholder card
Flip the `Piutang pelanggan` card in `ReportsController` from `is_placeholder => true` to a real `route`. The landing already filters cards by `Gate::allows(permission)` and `Route::has(route)`, so no other landing logic changes.

## Risks / Trade-offs

- **Payments invalidated *after* the as-of date** → A payment reversed later was still in force on a back-dated cutoff; filtering on current `status='ACTIVE'` understates the historical balance in that edge case. Mitigation: acceptable for the primary collections use case (run as-of today); document as a known limitation. A future refinement can use `status='ACTIVE' OR invalidated_at > :asOf` for audit-grade accuracy.
- **Performance on large ledgers** → the per-invoice payment-sum subquery joins `sale_payments`. Mitigation: aggregate in a single grouped subquery keyed by `sale_id` (one pass), as the existing sort subqueries do; scope by `setting_id` and `date <= asOf` first.
- **Float drift in stored amounts** → the sample CSV shows values like `32400000.000001`. Mitigation: round to 2 decimals when summing and when applying the `> 0` outstanding predicate, consistent with the rounding already used in `SalePaymentsController`.
- **Export staleness** → exporting after changing filters could ship a stale result. Mitigation: reuse the snapshot `hash()` gate from the Sale by Customer report.

## Open Questions

- **Credit-memo / unapplied customer-credit balances**: the card copy mentions "saldo memo kredit pelanggan", but the sample CSV lists only outstanding invoices. Decision for v1: invoices only; revisit whether to add unapplied-credit rows once the invoice report is validated against real data.
- **Description column source**: confirm `sales.note` is the intended "Deskripsi" (the sample shows free-text notes like "MASUK", "bima permai"); appears correct from the data but worth confirming against one known invoice during implementation.
