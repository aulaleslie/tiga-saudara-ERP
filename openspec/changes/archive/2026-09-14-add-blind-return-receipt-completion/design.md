## Context

Delivery 8 permits several partial return-dispatch batches to be approved and in transit concurrently. Each approved batch has an immutable product/quantity/condition/serial manifest, active obligation reservations, destination inventory deduction, and exclusive serial custody. The original location still has no operational way to recount or approve those goods, so reservations and custody cannot close and mandatory transfers cannot complete.

Return receipt reverses the operational locations but does not reverse the blindness rule: preparation must begin at zero and reveal none of the approved dispatch manifest. The system must also classify accepted inventory for the original business. Because the route snapshot resolves destination tax only, tax for an original PKP business is resolved from authoritative configuration at receipt processing time and snapshotted with that receipt.

## Goals / Non-Goals

**Goals:**

- Create one independent blind return-receipt lineage for each approved return-dispatch batch.
- Recount from zero with no expected products, quantities, serials, allocation, custody, obligation, or difference data exposed during preparation.
- Require exact manifest equality and exact serial identity at approval.
- Restore origin inventory, close return custody/reservations, and fulfill obligations atomically and idempotently.
- Resolve and snapshot original-business tax at processing time and preserve good/broken condition.
- Project `RETURN_DISPATCHED`, `AWAITING_RETURN`, or `COMPLETED` from authoritative remaining work.

**Non-Goals:**

- Partial receipt of one approved dispatch batch or combining several dispatch batches into one receipt.
- Serial substitution at receipt; substitution is allowed only during return dispatch.
- Delivery 10 reports, exports, dashboards, or general audit drill-down.
- Historical transfer backfill, legacy transaction support, or inventory-schema refactoring.

## Decisions

### Scope receipt lineage to its approved return-dispatch batch

Reuse the dispatch batch's non-null `return_batch_id` as the return-receipt lineage discriminator. A source dispatch can have at most one open receipt attempt and one approved receipt, while rejected attempts may be superseded by increasing revisions in that same lineage. Different source batches may have independent receipt attempts concurrently.

The database uniqueness model must retain the singleton lineage for forward types while permitting the same return batch identifier once for `RETURN_DISPATCH` and once for `RETURN_RECEIPT` because movement type remains part of the key.

Alternative: one transfer-wide return-receipt lineage. Rejected because it cannot independently receive concurrent dispatch batches.

### Keep preparation universally blind and empty

Receipt creation stores only source linkage and operational context; it copies no lines or serials from the dispatch. Both stock-visible and blind preparers receive the same preparation projection containing permitted document context plus only observations entered into the receipt. Scans may record products not expected by the hidden manifest so the operator is not coached by early match classification.

For an in-transit expected serial, live location remains the return-dispatch origin. Scanner resolution therefore follows the existing forward-receipt approach: resolve against the source manifest and active custody without exposing whether it is expected. Authoritative comparison happens later.

Corrections preserve rejected lineage and history but start empty again. Explicit empty-count confirmation permits submission of a genuinely empty observation while remaining distinguishable from unfinished work.

### Approve only an exact one-to-one manifest

Approval compares canonical product sets, exact integer base quantities, transfer condition, and normalized serialized sets against exactly one approved return dispatch. Missing, excess, unexpected, wrong-condition, duplicate, null-ID, or different serial observations are discrepancies. A receipt cannot partially accept its source batch and cannot combine multiple source batches.

Alternative: decrement a batch incrementally across several receipts. Rejected because the agreed operating model uses separate dispatch batches for separate receivals.

### Resolve origin classification at receipt processing time

Lock and reload the original location and setting during approval. A non-PKP origin receives the exact quantity into the applicable non-tax good/broken bucket and serialized `tax_id` becomes `null`. A PKP origin resolves its applicable tax at processing time: configured default first, otherwise the lowest-ID applicable active tax, and approval fails atomically when neither exists. Snapshot tax ID, name, rate, provenance, resolver timestamp, and setting identity on the receipt before inventory effects.

For same-business `PRESERVE` routes no return obligation exists, so operational return receipt is limited to mandatory cross-business routes. Source return-dispatch allocation remains immutable provenance; receipt application records its own origin-side classification and before/after snapshots.

Alternative: reuse destination policy tax or live tax configuration on later reads. Rejected because a PKP origin may differ from the destination and historical receipts must not be reinterpreted after configuration changes.

### Close custody, reservation, and obligation together

The executor locks in stable order: transfer, source dispatch, receipt, route policy, original location/setting, resolved tax, obligations, source reservations, products/stocks, movement serials, live serials, and active claims. It verifies every reservation is active and exactly matches its source dispatch line.

For each exact serialized receipt, the live serial must still match the source movement serial and active claim, remain located at the return-dispatch origin, and retain the dispatched condition/tax provenance. Approval moves it to the original location, applies origin tax identity, records immutable before/after history, closes movement custody, and removes the active claim.

Within the same transaction, destination-to-origin inventory transactions are recorded, reservations become `CLOSED`, obligation `returned_quantity` increases by the exact reserved quantity without exceeding required quantity, and fulfilled obligations become `FULFILLED`.

### Derive the transfer header after every receipt

Header state is a projection, not the concurrency ledger:

```
any ACTIVE return reservation                 => RETURN_DISPATCHED
no active reservation + any obligation open  => AWAITING_RETURN
no active reservation + every obligation full => COMPLETED
```

Approval must also ensure no unresolved pending receipt for already-closed work can later mutate completion. The action is scoped by movement/revision/action/idempotency key so replay returns the committed result without duplicate effects.

### Reuse receive permissions at the original side

Preparation uses `stockTransfers.receive.create`; review/approval uses `stockTransfers.receive.approval`. Every route validates active-setting ownership of the original transfer location, nested transfer/movement ownership, `RETURN_RECEIPT` type, matching batch/source dispatch, workflow version, and mandatory route policy.

Approval projections are neutral for users without `stockTransfers.view-system-stock` and detailed for privileged approvers. Preparation is universally blind regardless of that permission. Client-supplied expected manifests, comparison results, stock, tax, obligation, reservation, or custody values are ignored or rejected.

## Risks / Trade-offs

- [A receipt is approved twice] → Lock source lineage and scope idempotency; database and service invariants permit one approved receipt per dispatch batch.
- [Concurrent receipts corrupt obligations or completion] → Lock obligations/reservations in stable order and derive status only after all effects are applied.
- [Tax configuration changes during processing] → Lock setting/tax rows, resolve once inside approval, and persist descriptive snapshots.
- [Blind scanner leaks the expected manifest] → Sanitize all results and errors and test absence with distinctive protected values.
- [Serial custody drifts while receipt is open] → Re-query and lock live serial and claim identity at approval; roll back the complete transaction on mismatch.
- [MySQL identifiers exceed 64 characters] → Give every new foreign key and index an explicit short identifier and verify lengths.

## Migration Plan

1. Add receipt tax-resolution snapshot and any lineage/provenance fields with explicitly short MySQL constraint/index names.
2. Add receipt-lineage and relationship support while routes remain disabled.
3. Add blind preparation/scanning, comparison, projection, locked approval execution, and origin-side entry points.
4. Enable return receipt after focused migration, visibility, exact-manifest, tax, inventory, custody, obligation, concurrency, idempotency, and rollback verification.

Rollback disables receipt routes while retaining approved receipts and their immutable effects. It must not reopen closed reservations, restore transit claims, or reinterpret completed transfers.

## Open Questions

None.
