## Context

The archived movement foundation provides versioned movement attempts, immutable submission revisions, normalized lines and serials, explicit operational locations, optimistic locking, idempotency, and dormant custody fields. Production transfers remain workflow version `1`; the existing `/transfers/{transfer}/dispatch` action immediately allocates/deducts origin stock, moves serial locations to destination, creates legacy taxed-only return obligations, and advances the transfer header.

Delivery 5 introduces the version `2` forward-dispatch path. Dispatch preparation is an independent physical count: a blind preparer knows which approved products to count but not requested quantities or expected serials; a privileged user may see system expectations. Approval requires an exact request/count match and sufficient locked stock. Delivery 6 is not yet available, so activation must remain disabled even though the complete forward-dispatch path is built and testable.

## Goals / Non-Goals

**Goals:**

- Create, edit, explicitly confirm, submit, review, reject, correct, and approve forward-dispatch movement attempts.
- Reuse established transfer scan/search behavior without trusting client stock or serial metadata.
- Preserve deliberate zero counts and unexpected physical observations.
- Produce permission-aware blind and privileged projections with no payload leakage.
- Make approved movement the atomic version `2` origin-deduction boundary.
- Persist immutable applied allocation, inventory references, and active serialized transit custody.
- Maintain version `1` production compatibility and prevent premature version `2` activation.

**Non-Goals:**

- No destination receipt or destination stock addition.
- No PKP route-policy snapshot, destination reclassification, or new return-obligation policy.
- No return dispatch/receipt UI or inventory effects.
- No compensating reversal after approved dispatch.
- No production activation of workflow version `2` before Delivery 6.
- No full-suite verification requirement.

## Decisions

### Version-aware routing with a disabled activation boundary

Legacy version `1` transfers retain their current route, permission, service, header authority, serial movement, and return-obligation behavior. Version `2` forward dispatch uses new routes/actions and `TransferMovementDocumentService` plus a dedicated approval executor. A configuration/feature boundary defaults to disabled and no production path assigns version `2` in this delivery.

The version `2` executor is nevertheless complete and exercised directly and through gated feature tests. Delivery 6 will own the coordinated activation decision so a dispatched version `2` transfer can never be stranded without its matching receipt workflow.

Alternative considered: feed new dispatch manifests into legacy receipt. Rejected because legacy receipt consumes `transfer_products`, assumes serials already moved to destination, and cannot honor movement custody or blind recount.

### Product-aware, quantity-blind physical counting

The preparation projection contains every approved product identity and transfer condition. For a user without `stockTransfers.view-system-stock`, it omits requested quantities, expected serials, current stock, all buckets, allocation, remaining/maximum values, and differences. The user sees only their own entered quantities, scanned serial identities, and confirmation state. Privileged projections may include the omitted information.

The existing `TransferScanResolverService` and established barcode/conversion/serial/tokenized-search semantics are reused through a movement-specific adapter. Every mutation reloads product, conversion, location, condition, and serial authority. Unexpected products and alternate eligible serials are retained as physical observations; they are not described as expected/unexpected to a blind preparer.

### Explicit confirmation distinguishes zero from unfinished

Each approved product has a persisted count-confirmation record even when its counted quantity is zero. Movement lines gain `count_confirmed`; expected-product lines may therefore store `0.0000`. Scanning or changing a quantity confirms a positive count, while zero requires an explicit confirmation action. Submission requires all approved products to have a confirmed representation, but may contain an all-zero count. Unexpected positive lines are confirmed when deliberately added; unexpected zero lines are discarded.

This modifies the foundation's submission completeness rule: positive quantity is no longer required for an expected line when `count_confirmed = true`.

### Submission freezes observations; comparison occurs server-side

Submission revalidates the transfer revision and all live serial eligibility, then freezes the movement. It does not require the count to match the request and does not validate non-serialized counted quantity against available stock. This preserves an honest physical observation even when it differs from system state.

Canonical comparison keys are product ID plus the transfer's single condition. For non-serialized products, approved and counted base quantities must match exactly. For serialized products, product quantities and normalized serial-ID sets must match exactly. Missing, unexpected, excess, shortage, alternate, or duplicate observations make the comparison fail.

### Approval mismatch remains pending until explicit rejection

Approval reloads the immutable approved transfer revision and submitted movement under locks. A mismatch or insufficient stock blocks approval and rolls back without changing `PENDING`. The approver explicitly rejects with a reason, preserving the submitted revision and enabling a superseding correction draft.

A blind approver sees only a neutral match/failure result. `stockTransfers.dispatch.approval` does not imply stock visibility. A privileged approver may see request/count, stock, allocation, serial, and difference details. Same-user preparation and approval remains allowed when both permissions are held; actors are stored independently and self-approval is derivable for audit.

### Dedicated atomic forward-dispatch approval executor

The new executor does not call legacy `TransferMovementService::dispatch()` wholesale. In one transaction it locks transfer, movement, approved request lines, origin stock, and serials; revalidates revision, status, comparison, stock, condition, and serial eligibility; calculates authoritative allocation; deducts origin stock; records transactions and before/after snapshots; activates serial custody; approves the movement and history; and updates the compatibility header to `DISPATCHED`.

Any failure rolls back every effect. Idempotent replay returns the approved result without duplicating stock, custody, transactions, history, or status changes.

Non-serialized allocation retains the established non-tax-first rule within `GOOD` or `BREAKAGE`. Tax/non-tax bucket allocation is inventory provenance, not a request/count comparison dimension. Allocation drift does not make an otherwise exact physical count a mismatch. Detailed allocation remains visible only with stock visibility.

### Applied allocation and inventory references extend movement lines

Movement lines retain entered quantity and confirmation, and gain immutable approval fields for approved/applied total, applicable tax/non-tax or broken bucket quantities, before/after stock snapshot, and inventory transaction reference. The schema uses the foundation's decimal precision while existing integer stock constraints remain authoritative.

The exact movement, line, transfer revision, actor, and document identity are carried in transaction references/metadata rather than relying only on human-readable descriptions.

### Approved dispatch activates serial transit custody

For every exact approved serial, the movement-serial row changes from `INACTIVE` to `IN_TRANSIT` with custody timestamps and source/destination context. The live serial's `location_id` remains at the origin as its last confirmed location. It becomes unavailable to sale, dispatch, return, and other transfer selection through a centralized active-custody exclusion.

The database prevents more than one unresolved active custody claim for a live serial using a normalized custody/claim structure coupled to the movement serial if portable conditional uniqueness is inadequate. The approval executor locks the live serial and active claim boundary before activation. Delivery 6 will close custody and move `location_id` only on approved receipt.

### Authorization wrappers enforce action and operational side

Forward-dispatch preparation, editing, submission, cancellation, and correction require `stockTransfers.dispatch.create`, an approved eligible transfer, and active setting ownership of the transfer origin. Approval/rejection require `stockTransfers.dispatch.approval` and the same origin-side ownership. Service calls remain authorization-agnostic below these wrappers.

All browser-facing exceptions pass through stock-visibility-aware neutralization so blind users cannot infer expected quantity, availability, allocation, or serial provenance.

## Risks / Trade-offs

- [Delivery 5 can strand stock before receipt exists] → Keep version `2` activation disabled and preserve all version `1` production behavior until Delivery 6 coordinates cutover.
- [Explicit zero rows conflict with foundation assumptions] → Modify the capability deliberately and require `count_confirmed` rather than interpreting absence as zero.
- [Recording unexpected observations could disclose expectations] → Blind preparation labels all rows as operator-entered and defers expected/unexpected classification to authorized comparison projections.
- [Stock or serial state can drift after submission] → Lock and revalidate all authoritative rows at approval; leave the document pending on failure.
- [Active custody exclusion can be missed by a consumer] → Centralize availability scope/service changes and add focused regression coverage for sale, dispatch, return, and transfer selectors.
- [Movement and compatibility header can diverge] → Update both only in the approval executor transaction and verify consistency/idempotent replay.
- [Legacy and version `2` paths can accidentally cross] → Guard every entry point and executor by workflow version plus activation state and test crafted direct requests.

## Migration Plan

1. Add nullable/zero-safe movement-line approval allocation, confirmation, snapshot, and transaction-reference fields.
2. Add portable serial custody timestamps/context and an active-custody uniqueness mechanism.
3. Add projection, comparison, scan-adapter, authorization-wrapper, and approval-executor services.
4. Add forward-dispatch routes/components behind a default-disabled version `2` activation boundary; preserve legacy version `1` routing.
5. Run focused migration, domain, UI/payload, authorization, comparison, inventory rollback/idempotency, serial custody, and legacy regression tests.
6. Deploy with activation disabled. Delivery 6 will define and verify the coordinated enablement path.

Rollback before activation removes unused additive fields/surfaces while preserving version `1`. If test or non-production version `2` manifests exist, retain or export their audit evidence before destructive rollback. Once coordinated production activation occurs, forward rollback must preserve approved movements and custody rather than delete them.

## Open Questions

None. Production activation is intentionally delegated to Delivery 6 rather than left as an unresolved Delivery 5 implementation choice.
