## Context

Purchase receiving currently records every delivery as a pending `ReceivedNote`, posts inventory only when the note is approved, and derives a purchase status from cumulative approved receiving quantities. A purchase is `RECEIVED` only if every current purchase-detail quantity is fulfilled; otherwise it remains `RECEIVED PARTIALLY`. The existing global supplier-payment flow intentionally selects only non-archived purchases with exact `RECEIVED` status.

This feature closes a supplier shortfall at the purchase level after the operator decides no further delivery will arrive. It changes the purchase document to reflect what was actually accepted, but must not reverse or recreate approved stock, serial, transaction, or receiving history. The existing received-purchase correction workflow is intentionally limited to monetary changes and is not suitable for quantity normalization.

## Goals / Non-Goals

**Goals:**

- Provide one authorized, auditable completion operation for eligible `RECEIVED PARTIALLY` purchases.
- Base the final quantity of every retained purchase line exclusively on cumulative approved receiving-note detail quantities.
- Preserve identity and history for any line with approved receipt quantity, remove only lines without any approved receipt, and recalculate document monetary values atomically.
- Make the normalized purchase payable through the existing exact-`RECEIVED` global payment workflow.
- Surface the same authorization and eligibility outcome from purchase list, purchase detail, and receiving-history interfaces.

**Non-Goals:**

- Changing stock, serial numbers, approved received-note details, transaction logs, supplier, location, or purchase tax identity.
- Completing a purchase with pending receiving notes, no shortfall, or no approved receipt.
- Automatically refunding, invalidating, or reallocating historical payments; an overpaid normalized document is blocked for manual financial resolution.
- Reopening a completed shortfall purchase or accepting further receipts against it.

## Decisions

### Dedicated completion authority and audit aggregate

Add `purchases.receive.complete_shortfall` to the canonical receiving permission group. Enforce it at the endpoint and use the same permission for every UI entry point. Store each successful operation in a new immutable `purchase_receiving_completions` header linked to purchase, setting, actor, required reason, and structured before/after snapshot.

**Rationale:** Completion is a commercial decision to abandon outstanding supplier commitments, not ordinary data entry or approval. A dedicated record separates it from monetary correction history and explains why a quantity changed after stock receipt.

**Alternative considered:** Reuse `purchases.receive.approval` and receiving-note approval UI. Rejected because an approver could accidentally close a multi-delivery purchase while approving one note, and the decision concerns the aggregate purchase rather than one delivery.

### Complete at purchase scope after all pending notes are resolved

Expose a purchase-level confirmation screen/modal from the normal purchase list, purchase detail, and receiving-history workspace. The server independently verifies the purchase belongs to the active setting, is unarchived and `RECEIVED PARTIALLY`, has at least one approved receiving line and remaining shortfall, and has no `PENDING` received notes.

**Rationale:** Multiple receipts may contribute to a line. Closing before every pending note is approved or rejected could omit already-delivered goods and make later stock movements impossible to reconcile.

**Alternative considered:** Add a completion checkbox to each receive submission. Rejected because the final result cannot be known until approvals and all already-created notes are resolved.

### Normalize only document lines, from approved quantities

Within one database transaction, lock the purchase, its detail rows, received notes/details, and active payments. Aggregate only `APPROVED` receipt quantities by `po_detail_id`. For a detail with a positive aggregate, update that existing row's `quantity` to the aggregate; for a zero aggregate, delete the purchase detail only after confirming it has no receiving-detail history. Re-run the existing purchase normalizer using the retained rows and preserved financial inputs, then update purchase totals and status to `RECEIVED`.

**Rationale:** Existing approved receiving details, serial links, stock transactions, and reports reference the original purchase-detail IDs. In-place updates retain referential identity, while an entirely unreceived line has no approved inventory history to preserve.

**Alternative considered:** Create replacement detail rows for final quantities. Rejected because it breaks receipt/serial associations and creates ambiguous historical ownership.

### Financial integrity is checked before finalization

Recalculate `paid_amount`, `due_amount`, and `payment_status` from active purchase payments after normalization. If active paid amount exceeds the normalized total, reject the operation without mutations; otherwise retain existing payment records and update the derived purchase summaries.

**Rationale:** The common flow has no payment until the purchase becomes `RECEIVED`, but the ordinary payment path can have historical payments. Silent payment mutation would erase financial evidence or create untracked supplier credit.

**Alternative considered:** Automatically reduce the newest payment. Rejected because payment selection, supplier refund, and accounting treatment require an explicit later workflow.

### Completion is terminal for receiving

The completion transaction sets status to exact `RECEIVED`. Existing receive creation and approval paths must revalidate purchase status under lock and reject new/late receipts once a purchase is closed. The global payment service continues to rely on its existing exact-status rule.

**Rationale:** A completed shortfall means the organization has accepted the final supplier invoice scope. Further receipt would invalidate the normalized document and payment balance.

## Risks / Trade-offs

- [Concurrent approval or payment changes produce a stale preview] → Lock and revalidate all lifecycle, receipt, and payment data during completion; reject and require a fresh preview when state changed.
- [Deleting an unreceived row removes document evidence] → Persist full original/final line snapshots, quantities, and reason in the immutable completion audit record.
- [Header discount, shipping, and tax need a new final amount] → Reuse the project purchase normalizer rather than hand-calculating totals.
- [An old endpoint accepts a late receiving] → Add status guards to receiving creation and approval, backed by focused tests.
- [An active payment exceeds the normalized total] → Block completion and direct the operator to resolve payment explicitly; preserve all data.

## Migration Plan

1. Add the permission to the central catalog and synchronize permissions/role display using the existing seeding mechanism.
2. Add the non-destructive completion audit table, indexes, and foreign keys.
3. Deploy completion service, route/controller, shared preview UI, and receiving status guards in one release.
4. Deploy focused authorization, eligibility, normalization, audit, concurrency, payment, and UI visibility tests.
5. Rollback disables the route/UI and permission assignment. Completion records remain as audit evidence; restoring a previously normalized purchase requires a separate, explicitly designed corrective process rather than deletion or migration rollback.

## Open Questions

- None for the initial scope. Supplier-payment overage is deliberately blocked rather than automatically corrected.
