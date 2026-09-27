## Context

Workflow version `2` currently stops after approved forward dispatch: origin inventory has been deducted, movement-line allocation and transaction provenance are immutable, serialized goods hold exclusive active transit claims, live serial locations remain at the origin as last-confirmed custody, and the transfer header projects `DISPATCHED`. The movement foundation already supports `FORWARD_RECEIPT` attempts sourced from an approved forward dispatch, but deliberately exposes no operational receipt surface or inventory effect.

Delivery 6 must complete the no-return route without biasing destination counting. Receipt preparation is completely blind for every preparer, including users who separately hold stock visibility; visibility becomes relevant only during approval review. Because Delivery 7 will introduce authoritative PKP route snapshots, reclassification, and return obligations, this delivery can activate version `2` only where receipt classification is already deterministic: same-business and non-PKP-to-non-PKP transfers. Stock transfer has not been operationally used, so there is no legacy transfer transaction population to migrate or reinterpret.

## Goals / Non-Goals

**Goals:**

- Create and operate destination-side forward-receipt attempts sourced from the exact approved forward-dispatch movement.
- Start every receipt draft with no lines or expected manifest data and support scanner-first physical observation.
- Distinguish an untouched blank draft from an explicitly confirmed empty receipt.
- Preserve partial, empty, unexpected, and substitute-serial observations for comparison and audit.
- Compare submitted receipts canonically against the immutable approved dispatch manifest.
- Add destination inventory and close serialized transit custody only on exact receipt approval.
- Provide neutral blind and detailed privileged approval projections without trusting client comparison or inventory data.
- Activate version `2` only for same-business and non-PKP-to-non-PKP transfers.

**Non-Goals:**

- No PKP-involved version `2` activation.
- No route-policy snapshot, destination tax reclassification, or mandatory-return obligation.
- No return-dispatch or return-receipt operational surface.
- No partial receipt approval or partial custody closure.
- No historical transfer backfill, dual-write migration, or legacy transaction reconciliation.
- No full-suite verification requirement.

## Decisions

### Receipt drafts are empty and preparation is universally blind

`FORWARD_RECEIPT` creation references the approved `FORWARD_DISPATCH` but seeds no movement lines. Preparation projections expose only receipt document metadata, the transfer condition, the operator's own observed lines/serial identities, confirmation state, and permitted location labels. They never expose source-manifest products, quantities, serials, allocation, stock, shortages, or differences—even when the preparer has `stockTransfers.view-system-stock`.

This is stricter than dispatch preparation, where product identities are seeded. The objective is an independent destination count rather than a checklist. The approved dispatch remains server-side comparison authority.

### Empty receipt uses document-level physical confirmation

Because there are no expected lines to confirm, the movement stores a document-level physical-count confirmation and actor/timestamp. Any positive scan or deliberate line update marks the observation as started, while submitting a draft with no positive lines requires the operator to explicitly confirm that nothing was received. Clearing all observations does not silently preserve an earlier empty confirmation; the operator must reconfirm the empty result.

Alternative considered: create hidden zero lines for expected products. Rejected because their identifiers could leak through validation or client state and would couple completion to concealed expectations.

### Scanner behavior is adapted to destination observation

Receipt preparation reuses authoritative barcode, whole-unit conversion, serial, and tokenized product resolution but scopes products to the destination business catalogue and location. Non-serialized zero-system-stock products remain observable because physical evidence must not be constrained by expected stock. Serialized entries require individual serial scans; manual positive serialized quantities and product-barcode increments are rejected.

Unlike dispatch preparation, receipt serial validation requires an active claim belonging to the source forward dispatch. A physically scanned substitute or unrelated serial can still be retained as an observation through a receipt-specific observation representation, but it cannot be treated as eligible custody or applied at approval. Sensitive claim/source details stay server-side.

### Submission freezes any explicitly completed observation

Receipt submission revalidates destination ownership, transfer/movement/source aggregate identity, exact dispatch revision, scanner invariants, and serialized identity records. It permits partial, empty, unexpected, excess, shortage, and substitute-serial observations. No destination stock, live serial location, active claim, custody status, transfer header, or return state changes at submission.

### Comparison authority is the approved forward manifest

Canonical comparison uses product ID plus the transfer's single condition. Non-serialized base quantities must exactly equal approved dispatched quantities. Serialized product quantities and normalized live serial ID/text sets must exactly equal the approved dispatched set. Scan order and line order do not matter. Applied dispatch tax/non-tax buckets are not a recount comparison dimension; they are receipt inventory provenance.

Mismatch blocks approval and leaves the attempt `PENDING`. An approver explicitly rejects with a reason, after which a superseding correction draft starts empty rather than copying the rejected physical observations or hidden expectations.

### Approval review separates action authority from visibility

Destination preparation requires `stockTransfers.receive.create`; destination approval/rejection requires `stockTransfers.receive.approval`. Both require active-setting ownership of the destination. Same-user preparation and approval remain permitted when both permissions are held.

A blind approver receives only a neutral match/failure result and non-quantitative guidance. An approver with `stockTransfers.view-system-stock` may see exact dispatched-versus-received products, quantities, serial sets, bucket provenance, destination stock, and differences. Browser payloads never accept expected values, comparison results, claim identity, allocation, or stock snapshots from the client.

### Receipt approval is a dedicated locked atomic executor

The forward-receipt executor locks, in stable order, the transfer, source dispatch, receipt movement, source/receipt lines and serials, destination product stocks, global products, live serials, and active claims. It revalidates:

- workflow version, route eligibility, transfer `DISPATCHED` state, revision, movement type, source link, locations, and condition;
- exact canonical manifest comparison and whole-unit compatibility with current inventory columns;
- immutable source applied-bucket totals and transaction provenance;
- each live serial's identity, product, origin last-confirmed location, active claim ownership, and `IN_TRANSIT` custody.

Only after validation does it add destination stock using the source dispatch's immutable applied tax/non-tax and good/broken quantities, create receipt transactions/snapshots, move live serial locations to destination, write serial history, close movement custody, remove active claims, approve/history-stamp the receipt, and project the header to `COMPLETED`. Any failure rolls back all effects. Same-key replay returns the committed result without duplication.

### Initial activation is route-limited and forward-only

Delivery 6 replaces the global Delivery 5 hold with an eligibility resolver used at transfer creation/approval and every version `2` movement entry point:

- same origin and destination business: eligible;
- different businesses where both are non-PKP: eligible;
- any route involving a PKP business: ineligible until Delivery 7.

Eligible new transfers receive workflow version `2`; ineligible transfers remain version `1`. Direct crafted version `2` requests for ineligible routes are rejected. No stored legacy transfer migration is planned because the workflow has no operational history, but legacy code remains available for ineligible routes until later deliveries complete the cutover.

## Risks / Trade-offs

- [Hidden expectations make deliberate empty receipt ambiguous] → Persist explicit document-level confirmation and clear it whenever observations change.
- [Unexpected serials do not own transit custody] → Preserve them as immutable observations without moving or claiming them; exact approval remains impossible until correction matches the source dispatch.
- [Dispatch provenance may be incomplete or inconsistent] → Validate source applied buckets and serial custody before applying any destination effect; fail atomically rather than recompute hidden provenance.
- [Blind error text can leak the manifest] → Route all validation and comparison failures through preparation/approval-specific neutral projections and test raw HTML, JSON, session, and component state.
- [Concurrent receipt, stock opname, sale, or claim mutation races] → Lock live serial and claim boundaries and rely on active-claim uniqueness plus transaction rollback.
- [Route eligibility based on current PKP status can later drift] → Restrict Delivery 6 to routes where current classification yields no reclassification/return obligation; Delivery 7 replaces this with an approval-time policy snapshot.
- [Version `2` activation strands a dispatched transfer if receipt is inaccessible] → Enable assignment and dispatch only when the matching destination receipt surface, permissions, and route eligibility are available.

## Migration Plan

1. Add document-level physical-count confirmation and any missing receipt application provenance fields as additive nullable columns.
2. Add receipt preparation, scanner adapter, comparator, projections, authorization wrappers, and approval executor.
3. Add destination receipt and review surfaces while keeping return movement types dormant.
4. Add centralized version `2` route eligibility and switch eligible new transfers only after forward dispatch and receipt paths are both deployed.
5. Run focused migration, blind-payload, scanner, aggregate, comparison, inventory, serialized custody, rollback, idempotency, concurrency, and activation tests.
6. Deploy with PKP-involved routes forced to version `1`; Delivery 7 will own their coordinated cutover.

Rollback disables new version `2` assignment first. Because there is no preexisting legacy transfer population, no historical backfill reversal is needed. Any version `2` transfers created after activation must retain their movements, inventory references, and custody audit; rollback must not delete committed receipt or dispatch evidence.

## Open Questions

None. Delivery 7 intentionally owns PKP policy snapshots, reclassification, and mandatory-return behavior.
