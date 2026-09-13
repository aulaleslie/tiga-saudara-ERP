## Context

Workflow version `2` transfers on mandatory PKP routes now finish forward receipt in `AWAITING_RETURN` with immutable route-policy snapshots and product/condition return obligations. The movement foundation already models `RETURN_DISPATCH`, revisions, histories, and source linkage, while the approved forward-dispatch path provides established scanner, projection, comparison, inventory, and serialized-custody patterns. Return execution remains deliberately blocked in production.

Delivery 8 activates only the destination-to-origin dispatch leg. Unlike the original all-at-once exploration, operators may ship any positive subset of one or many obligations, and several approved batches may be in transit concurrently. Each batch is independently prepared and approved. Delivery 9 will recount and approve each corresponding receipt and only then credit returned quantities and origin inventory.

## Goals / Non-Goals

**Goals:**

- Prepare scanner-first partial return batches at the transfer destination without exposing protected quantities to blind users.
- Approve several concurrent batches safely without overcommitting any product/condition obligation.
- Permit eligible substitute serials and make each approved batch's selected serial set its exact downstream receipt manifest.
- Deduct destination-classified inventory and activate exclusive return-leg custody atomically on approval.
- Preserve immutable line allocation, transaction, serial, actor, history, policy, and obligation-reservation provenance.
- Reuse the existing movement permission and stock-visibility boundaries.

**Non-Goals:**

- Return-receipt preparation or approval, origin inventory addition, obligation fulfillment, or final completion.
- Requiring all products or the full outstanding transfer to ship in one batch.
- Requiring the original forward-dispatch serial identities on the return leg.
- Historical transfer backfill, legacy transfer-transaction compatibility, or inventory-schema refactoring.
- Delivery 10 reporting/export surfaces beyond operational detail required for this workflow.

## Decisions

### Model each return batch as an independent movement lineage

Each batch begins as a `RETURN_DISPATCH` draft bound to the approved `FORWARD_RECEIPT` that created the obligations and to the committed route-policy revision. A rejected attempt may be superseded by a correction, but every approved batch is a distinct lineage; it does not supersede another approved batch. The aggregate therefore permits many approved return-dispatch movements while retaining at most one open draft or pending attempt per lineage.

A batch may contain any positive subset of obligated products and quantities. Product identities may be seeded for scanning convenience, but expected/outstanding quantities are projection-protected. Empty and all-zero batches cannot be submitted.

Alternative: reuse one transfer/type revision stream with one approved attempt. Rejected because the foundation's single-approved invariant cannot represent concurrent partial shipments or independently received manifests.

### Reserve obligation capacity at approval, fulfill it at receipt

Persist immutable per-approved-batch reservation/allocation rows linked to the obligation and return-dispatch line. Under stable locks, approval enforces:

```
returned quantity
+ active approved in-transit quantity
+ quantity in this approval
<= required quantity
```

Draft and pending quantities do not reserve capacity and may become stale. Submission validates against current capacity for early feedback; approval is authoritative and locks obligations plus existing active reservations before accepting the batch. Rejected/cancelled drafts have no reservation. The reservation remains active until Delivery 9 approves its exact receipt; only then will that delivery increment `returned_quantity` and close the reservation.

Alternative: increment `returned_quantity` at dispatch. Rejected because dispatch proves shipment, not receipt, and would falsely fulfill obligations if goods never arrive.

### Treat one approved dispatch as one exact later receipt manifest

Approval freezes the batch's canonical product quantities, condition, and serial set. Delivery 9 must create a receipt against exactly one approved return dispatch and independently approve that receipt. Multiple dispatches are never merged into one source manifest, even if transported together.

Transfer status is a projection: at least one active reservation yields `RETURN_DISPATCHED`; after all current batches are received but obligations remain, it returns to `AWAITING_RETURN`; only Delivery 9 may project `COMPLETED` after every obligation is fulfilled and no unresolved return batch remains.

### Permit substitute serials but validate live destination eligibility

Return serial identity is not constrained to the forward-leg serial. It MUST match the line's canonical product and transfer condition, reside exactly at the destination/return-origin location, have normalized identity matching its live record, be operationally available, and have no active custody, reservation, sale, loss, prior dispatch, or return-process conflict. Tax identity must match the committed destination-side classification so serial provenance agrees with the inventory bucket being deducted.

Selected serials are re-queried and locked at submission and approval. Approval activates a new return-leg transit claim tied to that movement serial while leaving the live serial location at the destination until Delivery 9 confirms receipt at the origin.

Alternative: require original forward serials. Rejected by the business rule allowing equivalent inventory to be returned.

### Deduct the destination classification recorded by the route policy

The approved forward receipt normalized inventory to the destination business. Return dispatch therefore deducts the matching destination-side bucket, preserving `GOOD` versus `BREAKAGE`: `TAX` uses the applicable taxed bucket and snapshot tax, `NON_TAX` uses non-tax, and same-business `PRESERVE` follows authoritative stored provenance. Lines record exact decimal bucket allocation, locked before/after stock snapshots, and canonical transfer transaction references. Global product totals use strict underflow checks.

Inventory deduction, serial validation and claims, reservation rows, movement/history approval, and transfer-header projection commit in one locked transaction with action-scoped idempotency.

### Reuse dispatch permissions with destination-side ownership

Return preparation uses `stockTransfers.dispatch.create`; review/approval uses `stockTransfers.dispatch.approval`. Authorization is evaluated for the return origin—the original transfer destination—and validates tenant, location side, transfer/movement aggregate identity, movement type, workflow version, policy, and source receipt.

Preparation follows the forward-dispatch visibility model: product identities and the operator's observations are visible, but obligation/requested quantities, stock, allocations, differences, and expected serials require `stockTransfers.view-system-stock`. Approval projections are neutral for blind approvers and detailed for privileged approvers. All server mutations ignore client-supplied stock, capacity, allocation, policy, or comparison values.

### Activate behind a dedicated version-aware route gate

New v2 return-dispatch routes replace the legacy v2 rejection only for eligible transfers with mandatory-return policy and outstanding capacity. Legacy workflow version `1` behavior remains unchanged. Return-receipt routes stay dormant.

## Risks / Trade-offs

- [Concurrent approvals overcommit an obligation] → Lock obligations and active reservations in stable order and recompute capacity inside the approval transaction.
- [Draft becomes stale while another batch approves] → Treat draft/pending checks as advisory and reject authoritative approval cleanly without partial effects.
- [Substitute serial leaks into another operation] → Revalidate and lock live serials, then create exclusive active custody claims atomically.
- [Header status cannot express several batches] → Derive it from active reservations and remaining obligations; do not use the header as the concurrency ledger.
- [Blind projections disclose outstanding capacity through errors or candidate results] → Omit protected keys and use neutral, non-quantitative feedback for users without stock visibility.
- [Delivery 9 needs richer reservation state] → Persist explicit dispatch-line/obligation lineage and active/closed state now, without implementing receipt mutations.

## Migration Plan

1. Add additive reservation/allocation and any return-dispatch provenance fields and indexes; do not backfill historical transfers.
2. Add model relationships and aggregate constraints while return routes remain disabled.
3. Add preparation, scanner, projection, comparison, and locked approval services plus destination-authorized entry points.
4. Enable the dedicated return-dispatch route gate after focused migration, lifecycle, visibility, partial/concurrent approval, inventory, serial custody, idempotency, and rollback verification.

Rollback disables the new routes while retaining approved manifests, reservations, transaction provenance, and active custody. Any approved in-transit return batch must remain protected and be completed by compatible forward code; rollback must not delete or reinterpret it.

## Open Questions

None. Return receipt and final obligation fulfillment remain scoped to Delivery 9.
