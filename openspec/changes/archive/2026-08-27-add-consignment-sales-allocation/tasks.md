## 1. Schema and domain foundation

- [x] 1.1 Add additive, SQLite-compatible migrations for immutable consignment sold sources, sold serial identities, billing confirmation headers/lines, receipt-lot allocations, reservations/claims, source snapshots/hashes, lifecycle audit evidence, and Phase 3-ready linkage fields.
- [x] 1.2 Add foreign keys, restrictive deletion rules, decimal base-quantity columns, and indexes for dispatch idempotency, setting/supplier/product/location discovery, confirmation status, receipt-pool aggregation, return lookup, and serialized claim uniqueness.
- [x] 1.3 Implement Consignment Eloquent models, relationships, casts, status constants, tenant scopes, immutable-state guards, factories, and reference allocation using existing module conventions.
- [x] 1.4 Add read-only relationships from DispatchDetail, Consignment Receiving detail, serial provenance, Sales Return detail, POS checkout-sale, and related source models without changing their existing mutation behavior.
- [x] 1.5 Fix `update()` array filtering bug in Controller to actually discard unchecked serial allocations.
- [x] 1.6 Extract `acquireDeterministicLocks` in lifecycle service and use in both submit and approve to guarantee `PosReturn -> SaleReturn -> Location -> POS -> Dispatch -> Receiving` sequence.
- [x] 1.7 Ensure strict distribution of returned quantities across receipt lot allocations in reconciliation report.
- [x] 1.8 Refactor reconciliation to expose returned quantity at the sold-source level instead of distributing it across receipt lots to accurately match attribution logic.

## 2. Permissions and entry-point governance

- [x] 2.1 Register least-privilege permissions for allocation access, create/edit, submit, approve, and reject, following existing permission configuration and seeding patterns.
- [x] 2.2 Add Consignment navigation and routes for eligible sold sources, confirmations, approval actions, and allocation reconciliation behind permission checks.
- [x] 2.3 Enforce active-setting ownership and permission checks in controllers and domain services, including direct-request denial without foreign-record disclosure.
- [x] 2.4 Fix confirmation status filter and XSS vulnerability in `reconciliation/index.blade.php`.

## 3. Sold-source discovery

- [x] 3.1 Implement a discovery query for approved DispatchDetails at setting-owned consignment locations, covering ordinary Sales and POS-generated Sales while excluding pending/rejected dispatches, standard locations, bundles, services, and non-stock rows.
- [x] 3.2 Implement idempotent immutable sold-source capture with original base quantity, Sale/POS/dispatch identity, product/location/tax/serial snapshots, source hash, and one-row-per-dispatch enforcement.
- [x] 3.3 Link POS sold sources to persisted checkout-sale context for traceability without deriving or duplicating the dispatch quantity.
- [x] 3.4 Implement historical POS quantity reconstruction from current dispatch quantity plus executed cash-return evidence, producing actionable blockers for missing, conflicting, or ambiguous evidence.
- [x] 3.5 Add a repeatable discovery command/service and read-only preview that reports created, existing, excluded, and blocked sources without rewriting immutable captures.

## 4. Return-aware eligibility

- [x] 4.1 Implement effective-return resolution by DispatchDetail using only physically received Sales Returns in AWAITING SETTLEMENT or COMPLETED, including linked POS Return execution.
- [x] 4.2 Implement serialized return deduction using return detail serial IDs and immutable serial history so cleared current dispatch pointers do not lose original identity.
- [x] 4.3 Implement sold-side eligibility calculations for original sold, effective returned, pending reserved, approved allocated, remaining quantity, and conflict/blocker evidence.
- [x] 4.4 Revalidate return evidence under lock during submission and approval and reject stale or over-capacity confirmations without partial changes.

## 5. Supplier and receipt-pool allocation

- [x] 5.1 Implement serialized supplier/receipt resolution through approved non-reversed Consignment Receiving pivots and history, with product, location, setting, and supplier consistency checks.
- [x] 5.2 Enforce read-only serialized allocation, one active/approved claim per sold serial, and explicit blockers for missing, reversed, conflicting, or ambiguous lineage.
- [x] 5.3 Implement non-serialized eligible receipt-pool queries for the selected supplier, product, setting, and exact sold source location with received, reversed, reserved, allocated, and remaining quantities.
- [x] 5.4 Implement explicit decimal base-quantity allocation across one or more same-supplier receipt lots, requiring exact totals and rejecting cross-location, cross-supplier, cross-product, FIFO/LIFO substitution, and over-allocation.
- [x] 5.5 Snapshot each selected receipt lot's receiving/receival references, supplier, unit cost, unit DPP, and tax evidence for immutable approval and future Phase 3 billing.

## 6. Confirmation lifecycle and concurrency

- [x] 6.1 Implement one-supplier confirmation draft creation/editing with canonical sold/return/receipt snapshots and hash-based stale-source detection; allow deletion only while draft.
- [x] 6.2 Implement transactional submission that locks the header, sold sources/dispatches, receiving details/receivings, serial identities, and competing claims in deterministic ID order before establishing reservations.
- [x] 6.3 Implement transactional approval that revalidates all authoritative evidence, converts reservations to immutable approved allocations exactly once, and marks the confirmation ready for future billing.
- [x] 6.4 Implement required-reason rejection that releases reservations atomically, preserves audit evidence, and permits controlled revision/resubmission.
- [x] 6.5 Add explicit guards proving approval creates no Purchase, PurchaseDetail, ReceivedNote, payable, PurchasePayment, payment eligibility, inventory/cost mutation, serial mutation, or Sales/POS/dispatch/return mutation.

## 7. User interfaces and reconciliation

- [x] 7.1 Build the eligible-sold-source and confirmation index/detail surfaces with source type, Sale/POS reference, dispatch/location, product, serial, sold, returned, reserved, allocated, remaining, status, and blocker visibility.
- [x] 7.2 Build the one-supplier draft/edit workflow with read-only serialized ownership and manual non-serialized receipt-lot quantities, server-side validation feedback, exact-total guidance, and source snapshot review.
- [x] 7.3 Build submit, approval, rejection, revision, and audit surfaces with explicit reservation and financially-inert Phase 2 messaging.
- [x] 7.4 Extend consignment reconciliation filters and totals for received, reversed, sold, returned-before-billing, waiting-reserved, approved-allocated, remaining, source transaction, confirmation status, serial, and unresolved blockers.
- [x] 7.5 Ensure pagination and aggregate queries use the new indexes and preserve existing Phase 1 custody reconciliation semantics for records without sales allocations.

## 8. Focused verification

- [x] 8.1 Add focused migration/model tests for defaults, decimal precision, relationships, casts, tenant scopes, indexes, idempotency constraints, immutable state, and SQLite compatibility.
- [x] 8.2 Add focused discovery tests for ordinary Sales, posted POS, mixed standard/consignment sourcing, historical capture, duplicate discovery, unsupported rows, and ambiguous reconstruction blockers.
- [x] 8.3 Add focused serialized-lineage tests for exact supplier resolution, returned serials with cleared dispatch pointers, missing/conflicting/reversed lineage, and duplicate active claims.
- [x] 8.4 Add focused non-serialized tests for manual multi-lot allocation, exact totals, same-location/supplier enforcement, decimal base quantities, and sold-side/receipt-side capacity limits.
- [x] 8.5 Add focused lifecycle and concurrency tests for draft non-reservation, submission reservation, approval conversion, rejection release, revision/resubmission, stale returns, duplicate actions, lock ordering, and transaction rollback.
- [x] 8.6 Add focused authorization tests for permission boundaries, tenant isolation, hidden navigation/actions, and direct forged requests.
- [x] 8.7 Add focused financial/inventory non-mutation tests proving confirmation lifecycle leaves Purchase, payment, stock, costs, serial state, Sales, POS, dispatch, and return records unchanged.
- [x] 8.8 Add focused reconciliation tests for received/sold/returned/reserved/allocated/remaining totals, standard-only parity, filters, and blocker visibility.
- [x] 8.9 Run only the new Consignment allocation tests and directly affected existing Consignment, Sales/POS dispatch, and return tests; record any unrelated failures encountered without requiring or running the full application test suite.
