## Context

The existing stock-transfer aggregate in `Modules/Adjustment` uses the transfer header as both request state and operational state. `TransferLifecycleService` invokes `TransferMovementService` directly: dispatch and receipt immediately mutate stock, update serialized-product locations, and advance header statuses. Allocation and serial evidence is partly stored on `transfer_products` as quantities and JSON snapshots. This is insufficient for independently counted, submitted, approved, rejected, and corrected physical movements.

Earlier deliveries already established explicit transfer condition, request revision/history, scanner behavior, and `stockTransfers.view-system-stock`. This change is the additive foundation for later movement deliveries. It must coexist with the current production lifecycle without routing any current action through the new records.

The schema permits tax and non-tax inventory for every business, while normal operational data follows PKP-to-tax and non-PKP-to-non-tax usage. This change does not enforce, migrate, or reclassify those buckets; later route-policy and receipt changes will use the movement foundation.

## Goals / Non-Goals

**Goals:**

- Represent each forward/return dispatch/receipt submission as a normalized, independently auditable movement attempt.
- Bind every attempt to the exact approved transfer revision and every recount to its exact approved source manifest.
- Preserve rejected and cancelled attempts while allowing correction through a superseding revision.
- Prevent concurrent open attempts and multiple approved attempts for the same transfer movement type.
- Establish workflow-version compatibility, movement permission families, scoped idempotency, and dormant serialized transit-custody storage.
- Preserve decimal base-unit quantities and the existing single `GOOD`/`BREAKAGE` condition.
- Prove the foundation with focused tests.

**Non-Goals:**

- No production routes, controllers, Livewire movement screens, actions, or browser projections.
- No change to existing dispatch, receipt, return, header-status, stock, inventory transaction, tax, return-obligation, or serial-location behavior.
- No route-policy snapshot or PKP/non-PKP reclassification implementation.
- No activation of serial transit custody or availability filtering.
- No general movement reports, exports, corrections, or compensating movements.
- No full-suite test requirement.

## Decisions

### One movement row is one immutable submission attempt

`transfer_movements` stores attempts rather than a mutable logical document header. An attempt is identified by `(transfer_id, type, revision)`. A rejected attempt remains immutable and correction creates the next revision with `supersedes_movement_id` pointing to it. This avoids a separate revisions table while preserving exactly what was submitted and rejected.

Types are `FORWARD_DISPATCH`, `FORWARD_RECEIPT`, `RETURN_DISPATCH`, and `RETURN_RECEIPT`. States are `DRAFT`, `PENDING`, `APPROVED`, `REJECTED`, and `CANCELLED`. A draft may be edited in place; submission freezes its lines and serials. Rejected, cancelled, and approved attempts never return to draft.

Alternative considered: keep one mutable movement row plus revision snapshots. That introduces another aggregate layer and makes it easier for current-state fields to diverge from immutable snapshots.

### Attempts carry explicit dependencies and locations

Every attempt stores the approved transfer revision, stock condition, operational source location, and operational destination location. Receipt attempts reference the exact approved dispatch through `source_movement_id`; return dispatches will later reference the accepted forward receipt, and return receipts reference the approved return dispatch. The service validates compatible type ordering, transfer identity, approved source state, locations, and condition.

Movement identity for logs and future presentation is deterministic from the transfer document number, movement type code, and revision, for example `TRF-...-FD-01`. This foundation need not introduce a separately sequenced public-number column.

### Normalized decimal lines and serial rows

`transfer_movement_lines` stores one row per product for an attempt, unique by `(transfer_movement_id, product_id)`, using the inventory-compatible decimal base-unit precision rather than assuming every future movement is integral. Scan order and repeated product rows are canonicalized into that row before persistence.

`transfer_movement_serials` stores one row per selected serial, unique within an attempt, and references its movement line. It snapshots serial text, product, condition, and tax provenance needed to explain the submitted manifest without relying on mutable serial state. Serialized line quantity must equal the number of unique serial rows at submission.

### Dormant transit custody is represented on movement serials

Movement serial rows include custody lifecycle fields sufficient for later dispatch approval to mark an approved serial in transit and later receipt approval to close that custody. This delivery leaves those fields inactive and does not change `product_serial_numbers.location_id` or availability queries.

Later deliveries will keep `location_id` as the last confirmed location while an unresolved approved dispatch-serial row represents transit custody. Receipt approval will change the serial location and close custody. A database-supported active-custody uniqueness strategy will be activated with the first inventory-moving delivery, once its exact transaction boundary is introduced.

Alternative considered: move a serial to its destination at dispatch, matching current behavior. That falsely represents receipt confirmation and could expose in-transit serials to destination workflows.

### Workflow version separates legacy and future authority

Add `workflow_version` to transfers with existing and newly created records defaulting to legacy version `1`. This change does not opt any transfer into version `2`. A later cutover change will explicitly assign version `2` at the approved boundary.

For version `1`, the existing transfer header remains authoritative. For future version `2`, approved movement records will become operational authority while legacy header statuses remain an atomically maintained compatibility projection for existing views and reports. Delivery 4 only stores the version boundary; it does not derive or change statuses.

### Lifecycle invariants are enforced by locks plus portable indexes

The movement service locks the transfer and relevant attempts when creating, submitting, rejecting, cancelling, or superseding a draft. It rejects a second `DRAFT`/`PENDING` attempt or a second approved attempt for the same `(transfer, type)`. The unique revision key is database-enforced; conditional active-state uniqueness remains service-enforced under locks for MySQL/SQLite portability.

Action idempotency is scoped by movement, revision, and action. Repeating a completed action with the same key returns the existing result and does not duplicate state or history. A dedicated movement history records actor, transition, reason, metadata, and idempotency key.

### Drafts are shared within authorization scope

An authorized actor on the correct operational side may edit an open draft created by another authorized actor. The record retains creator and last editor, and optimistic revision/updated-state validation prevents silent concurrent overwrite. Submission records the submitting actor independently.

### Movement permissions are additive and do not imply visibility

Register:

- `stockTransfers.dispatch.create`
- `stockTransfers.dispatch.approval`
- `stockTransfers.receive.create`
- `stockTransfers.receive.approval`

A compatibility migration grants both new dispatch permissions to roles holding `stockTransfers.dispatch`, and both new receipt permissions to roles holding `stockTransfers.receive`. It does not grant `stockTransfers.view-system-stock`. Current production routes continue checking legacy permissions until later cutover changes.

Movement approval permission and stock visibility remain independent. This delivery exposes no production movement payload. Later surfaces must omit authoritative quantities, manifests, allocations, serial expectations, and differences for users lacking stock visibility; privileged users may receive those fields.

### Approval-time effects remain out of scope

The foundation may exercise state transitions in focused domain tests, but approving a dormant movement performs no inventory, tax, serial-location, return-obligation, or transfer-header mutation. Later deliveries must introduce those effects atomically with the applicable approval transition rather than attach observers to the generic model.

## Risks / Trade-offs

- [Dormant tables could be mistaken for an active workflow] → Add no production routes or UI, keep workflow version at `1`, and document that movement approval has no operational effect in this delivery.
- [Service-enforced open-attempt uniqueness can race] → Lock the parent transfer before querying or creating attempts and retain database uniqueness for revision identity.
- [Compatibility permission grants preserve combined duties] → Preserve current access initially, record the explicit mapping, and allow administrators to separate preparation and approval later.
- [Decimal lines differ from integer legacy transfer quantities] → Use the same decimal precision as inventory while validating current whole-unit scanner behavior; do not alter legacy columns.
- [Future serial custody design could need stronger constraints] → Store normalized serial-manifest identity now but defer active custody and its final uniqueness mechanism to the first inventory-moving delivery.
- [Header and movement state can diverge after future cutover] → Make movements authoritative for version `2`, centralize projection updates in the later lifecycle transaction, and add consistency tests then.
- [New foreign keys could make historical deletion behavior stricter] → Use restrictive retention for submitted operational evidence and allow only safe draft cancellation; do not cascade away movement audit history independently of an explicitly deleted never-operational transfer.

## Migration Plan

1. Add `transfers.workflow_version` with a non-null default of `1`; do not backfill any record to version `2`.
2. Create movement, line, serial, and movement-history tables with foreign keys, unique revision/line/serial constraints, and lookup indexes compatible with MySQL and SQLite.
3. Register the four permissions through the established centralized permission synchronization path.
4. Run a one-time compatibility assignment from legacy dispatch/receive permissions to the corresponding new create/approval permissions without changing stock visibility.
5. Deploy with no route or inventory integration. Verify existing transfers continue through the legacy lifecycle unchanged.

Rollback may remove the dormant tables and workflow-version column while they contain no operational version `2` data. Permission rollback may remove the four unused permissions but must not otherwise rewrite role assignments. Once a later delivery activates version `2`, rollback must instead preserve movement evidence and use a forward compatibility migration.

## Open Questions

None for this foundation. Inventory effects, active transit custody, header projection updates, route tax policy, privileged/blind UI projections, and compensating corrections are intentionally delegated to their respective later changes.
