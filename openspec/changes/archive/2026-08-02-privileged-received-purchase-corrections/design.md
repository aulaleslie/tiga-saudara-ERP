## Context

Received purchases are currently immutable because the normal purchase edit flow rebuilds detail rows. Approved receiving notes reference those rows and drive stock, serial-number, transaction-log, price, and later sale-HPP behavior. Purchase payments are independent active/invalidated records, while the purchase header's paid, due, and payment status values are derived summaries. Sale detail HPP is stored as a mutable cost snapshot and can already be replayed chronologically by a backfill command.

The correction is exceptional: a supplier's information was wrong after goods and payment had already progressed. It must be narrow, deliberate, tenant-scoped, and audit-safe.

## Goals / Non-Goals

**Goals:**

- Allow only explicitly authorized users and Super Admin to correct supported monetary information on received purchases.
- Preserve receipt, serial, stock-quantity, and purchase-detail identities while making document and payment totals internally consistent.
- Record immutable, explainable correction history.
- Make costing and downstream HPP replay explicit, previewable, deterministic, and independent from document correction.

**Non-Goals:**

- Editing product identity, ordered/received quantity, supplier, purchase date, receipt location, tax identity, or receipt/serial history after receiving.
- Changing stock quantities, creating a general accounting journal, supplier-credit workflow, or automatically rewriting HPP when a correction is saved.
- Replacing the existing normal purchase edit, normal receiving approval, or general payment-edit workflows.

## Decisions

### Separate correction workflow and permission

Create a dedicated received-purchase correction policy/action rather than relaxing `purchases.update`. It applies only to purchases in `RECEIVED` or `RECEIVED PARTIALLY` status and enforces setting ownership in the UI and backend. The canonical permission is `purchases.received.correct`; Super Admin follows the application's super-admin authorization path while still producing an audit record.

**Rationale:** `purchases.update` is routine document editing and is intentionally unavailable after receipt. A separate permission is assignable, visible in role management, and does not inadvertently open historical stock changes.

**Alternative considered:** Make `purchases.update` bypass the received lock. Rejected because it would reuse unsafe delete-and-recreate detail behavior and give ordinary editors exceptional financial authority.

### Preserve source rows and use a correction audit aggregate

Update only allowed existing purchase and purchase-detail monetary fields in place. Add a correction header/history record with purchase ID, setting ID, actor, reason, timestamp, optional cost-recalculation state, and immutable before/after JSON; add child entries or structured payload for field-level changes and selected payment before/after values. Lock the purchase, affected details, and active payments in one transaction before validating and saving.

**Rationale:** receipt-note detail foreign keys and serial history continue to point at the original purchase-detail IDs. The history provides a durable explanation without relying on mutable timestamps or logs.

### Correction input is constrained to money fields

The correction UI starts from the existing purchase document, supports per-line price and line discount fields already represented by the document, plus global discount and shipping. It recomputes stored line subtotals/tax and header totals through the existing normalization rules. It rejects product/quantity/supplier/date/location changes and requires a non-empty reason.

**Rationale:** these are the stated supplier-error cases and can be corrected without reversing inventory quantity history.

### Active payments are reconciled as part of correction

After calculating the corrected document total, use active `purchase_payments` as the source of truth. If exactly one active payment exists, set that payment to the corrected total. If more than one exists, require the user to select exactly one active payment; compute its replacement amount as current amount plus document-total delta, display before/after values, and reject the correction if it would become negative. If no active payment exists, leave payments unchanged and recompute the due balance. Persist the selected payment's before/after data in the correction audit.

If unchanged non-selected active payments would leave paid amount above the corrected total, reject the correction rather than silently creating supplier credit.

**Rationale:** it gives the requested one-versus-many interaction while keeping payment history and payable summaries consistent.

### Costing is explicit and replay-based

Saving a correction records affected product IDs and earliest approved receipt effective time but does not modify `product_prices` or sale snapshots. A separate privileged recalculation action offers a preview and then executes an atomic scoped replay from that receipt forward. The replay uses the same bucket, effective-date ordering, tax-exclusive DPP, return-consumption, negative-stock, and deterministic same-date semantics as sales cost snapshot backfill. It updates current purchase-price rows for affected products and, only when the user opts in, later eligible sale detail HPP snapshots. A correction-specific snapshot source/metadata records the correction that caused a rewrite without overwriting authoritative imported-HPP snapshots.

**Rationale:** historical profit reporting changes are consequential and must not be a hidden side effect of correcting a supplier invoice.

**Alternative considered:** Run global normalization/backfill automatically on save. Rejected because it is slow, changes unrelated history, and obscures the decision to rewrite sale HPP.

### Header adjustments are allocated deterministically for cost replay

For each corrected stock-managed received line, calculate tax-exclusive line DPP, allocate global discount and shipping proportionally by positive line DPP, and reconcile rounding to the stable last eligible line. Global discount lowers cost; shipping increases cost; input tax never increases cost. The allocated adjustments are derived at replay time from stored corrected document values rather than duplicated into receipt records.

**Rationale:** it makes a rerun reproducible while preserving receipt history and aligns costs with the corrected supplier invoice.

## Risks / Trade-offs

- [Historical reports change after downstream replay] → Require explicit operator action, preview counts/range, mandatory reason, audit linkage, and confirmation.
- [Payment correction causes overpayment or negative selected payment] → Validate against all active payments and block unsupported supplier-credit states.
- [Concurrent receive/payment/correction operations] → Lock purchase, details, relevant receipt information, and active payment rows in one database transaction; revalidate status and totals before commit.
- [Large sales history makes replay slow] → Scope to affected products and from the earliest impacted receipt; reuse chunked/batched replay patterns and present progress/result summary.
- [Imported HPP is intentionally authoritative] → Exclude imported HPP snapshots from correction-driven overwrite and surface skipped counts in preview/result.
- [Header allocation rounding drift] → Use decimal arithmetic and a deterministic final-line remainder.

## Migration Plan

1. Add the canonical permission and safely seed/remap role permissions without removing existing permissions.
2. Add correction audit tables and non-destructive indexes/foreign keys.
3. Deploy the correction UI, policy, financial reconciliation service, and tests with recalculation disabled until its replay service is available.
4. Deploy scoped cost preview/replay and audit-linkage support; enable the explicit recalculation action.
5. Rollback disables new UI/actions. Audit rows remain intact; no receipt, stock, or payment deletion is required. If a replay has already changed cost snapshots, restoration requires a subsequent explicit replay using the retained correction audit history rather than schema rollback.

## Open Questions

- Confirm the exact role/flag that denotes Super Admin in this application so its bypass is implemented consistently with existing authorization.
- Confirm whether tax-rate or tax-reference corrections belong in this privileged workflow; this proposal limits monetary correction to protect tax audit semantics.
- Confirm whether supplier-credit/overpayment handling should become a later capability instead of blocking such corrections.
