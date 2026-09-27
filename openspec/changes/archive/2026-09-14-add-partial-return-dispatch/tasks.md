## 1. Persist Return-Batch Lineage and Obligation Reservations

- [x] 1.1 Add additive migrations for stable return-batch lineage and per-approved-line obligation reservations, including exact decimal quantities, active/closed state, source movement references, actor/timestamps, idempotency, and uniqueness/index constraints needed for concurrent locking.
- [x] 1.2 Extend return movement, line, serial, obligation, and active-custody models with casts, fillables, relationships, immutable provenance, and helpers for returned, actively in-transit, and available obligation quantities.
- [x] 1.3 Add focused migration/model tests covering multiple batch lineages, correction revisions, reservation identity, decimal precision, and database constraints without historical backfill.

## 2. Activate Partial Return-Dispatch Preparation

- [x] 2.1 Adapt the movement document lifecycle to create multiple independent `RETURN_DISPATCH` lineages against the exact approved forward receipt while preserving one-open-attempt and one-approved-attempt rules within each lineage.
- [x] 2.2 Implement a destination-side return-dispatch preparation service that creates/resumes a selected batch, exposes obligated product identities, accepts any positive subset of one or many products, and rejects empty/all-zero submission.
- [x] 2.3 Reuse the scan resolver for destination inventory with tenant/catalog, location, product, conversion, condition, workflow revision, policy, and obligation eligibility validation while allowing zero recorded stock to be observed before approval.
- [x] 2.4 Restrict serialized products to direct serial selection, allow substitutes unrelated to forward serial identity, prevent manual positive serialized quantities, and revalidate exact live identity, destination location, product, condition, tax classification, availability, reservation, and active custody at submission.
- [x] 2.5 Revalidate unreserved obligation capacity at submission for early stale-draft feedback without creating a reservation or inventory/custody effect.
- [x] 2.6 Preserve immutable rejection and cancellation behavior and implement corrections that retain batch/source lineage without copying unsafe submitted state or altering other batches.

## 3. Enforce Authorization and Visibility-Safe Projections

- [x] 3.1 Implement return-dispatch preparation and approval projection services that omit obligation quantities, returned/in-transit/outstanding totals, stock, allocations, expected serials, capacity, and differences for users lacking `stockTransfers.view-system-stock`.
- [x] 3.2 Provide neutral non-quantitative blind errors and detailed privileged comparison feedback without exposing protected keys or sentinel values through HTML, JSON, session, validation, ambiguous candidates, or client state.
- [x] 3.3 Add destination-authorized controller actions and version-aware routes for batch creation/resume, scan, quantity/serial mutation, submit, review, approve, reject, cancel, and correct using existing dispatch create/approval permissions.
- [x] 3.4 Enforce tenant, destination-location side, transfer/movement ownership, `RETURN_DISPATCH` type, batch lineage, approved source receipt, route-policy revision, mandatory-return, and workflow-version invariants on every entry point while leaving return receipt unavailable.
- [x] 3.5 Build scanner-first preparation and approval-review views that support product selection, substitute serial entry, multi-product batches, explicit operator observations, and visibility-aware output.

## 4. Compare and Approve Concurrent Return Batches Atomically

- [x] 4.1 Implement canonical return-dispatch comparison against product/condition obligations, validating positive exact base quantities and unique serial counts without requiring all obligations or original forward serials.
- [x] 4.2 Implement a locked approval executor that acquires transfer, policy, obligation, active reservation, product, destination stock, transaction, live serial, and custody rows in stable order and revalidates the entire aggregate.
- [x] 4.3 Atomically enforce `returned + active in transit + approving <= required` for every obligation and persist one immutable active reservation per approved movement line so competing approvals cannot overcommit capacity.
- [x] 4.4 Deduct exact destination-classified good/broken inventory, preserve strict decimal/integer invariants as applicable, reject stock/global-total underflow, and record before/after snapshots and canonical transfer transaction references.
- [x] 4.5 Re-query and lock each substitute serial at approval, verify identity/product/location/condition/tax/status/reservation/custody, and activate exclusive return-leg transit custody while retaining its live destination location.
- [x] 4.6 Freeze the exact approved batch manifest and apply movement history, reservation, inventory, transaction, custody, and transfer-header `RETURN_DISPATCHED` projection once under action-scoped idempotency with complete rollback on any failure.
- [x] 4.7 Keep `returned_quantity`, origin inventory, live serial origin relocation, reservation closure, return-receipt execution, and final transfer completion unchanged for Delivery 9.

## 5. Focused Verification

- [x] 5.1 Add focused preparation/scanner tests for partial single-product and multi-product batches, conversion scans, zero-stock observations, empty rejection, non-obligated products, serialized substitute selection, stale submissions, and corrections.
- [x] 5.2 Add focused authorization/projection tests for destination ownership, aggregate binding, legacy/v2 routing, return-receipt dormancy, blind payload absence, neutral errors, privileged details, and crafted protected inputs.
- [x] 5.3 Add focused approval/inventory tests for destination tax/non-tax and good/broken deductions, exact provenance, insufficient stock, global underflow, idempotent replay, and atomic rollback.
- [x] 5.4 Add focused concurrency tests proving multiple valid batches may be in transit while competing approvals cannot overcommit the same obligation and unrelated obligations can approve independently.
- [x] 5.5 Add focused serialized custody tests for substitute serial approval, live-state drift, incompatible tax/condition/location, duplicate selection, competing operational use, exclusive claims, and rollback.
- [x] 5.6 Run the new and directly affected stock-transfer test files with `composer test:fresh-sqlite` or focused `php artisan test` filters and record results; do not require a full-suite run.
