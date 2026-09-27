## 1. Extend Receipt Lineage and Tax Provenance

- [x] 1.1 Add additive receipt tax-resolution snapshot fields and any required return-receipt lineage/provenance indexes using explicit MySQL identifiers no longer than 64 characters.
- [x] 1.2 Extend movement, line, serial, reservation, obligation, route, and tax relationships/casts to expose immutable per-batch receipt and processing-time classification provenance.
- [x] 1.3 Add focused migration/model tests for non-null lineage behavior, one receipt lineage per return batch, correction revisions, tax snapshots, and MySQL-safe constraint/index names.

## 2. Build Completely Blind Return-Receipt Preparation

- [x] 2.1 Adapt movement creation so `RETURN_RECEIPT` uses its approved source dispatch's `return_batch_id`, permits independent concurrent batch lineages, and enforces one open or approved receipt within each lineage.
- [x] 2.2 Implement a return-receipt preparation service that validates workflow version, mandatory route policy, original-side locations, approved source dispatch, active reservations, and source/batch identity while creating an empty draft.
- [x] 2.3 Implement scanner-first product, conversion, and serial observation from zero, including source-manifest/custody-aware resolution for in-transit serials without exposing whether an observation is expected.
- [x] 2.4 Support explicit document-level empty-count confirmation with optimistic locking and clear it atomically only after successful observation mutation.
- [x] 2.5 Revalidate source lineage, transfer context, exact live serial identity, and draft concurrency at submission without applying inventory, tax, custody, reservation, obligation, or header effects.
- [x] 2.6 Ensure rejected receipt corrections preserve history and source-batch lineage while starting with no copied products, quantities, serials, comparison, or expected data.

## 3. Enforce Origin Authorization and Blind Projections

- [x] 3.1 Implement a universally blind preparation projection identical for blind, stock-visible, and Super Admin preparers and containing only permitted context plus operator-entered observations.
- [x] 3.2 Sanitize resolved, rejected, not-found, ambiguous, validation, session, and exception responses so preparation never reveals hidden source products, quantities, serials, custody, reservations, stock, tax, or differences.
- [x] 3.3 Implement permission-aware approval projections with neutral non-quantitative results for blind approvers and exact server-derived comparison/provenance for stock-visible approvers.
- [x] 3.4 Add origin-side controller actions and version-aware routes for create/resume, scan, set quantity, confirm empty, submit, review, approve, reject, cancel, and correct using existing receive create/approval permissions.
- [x] 3.5 Enforce active-setting ownership, nested aggregate binding, movement type, source movement, return-batch lineage, route policy, transfer condition, workflow version, and lifecycle state on every HTTP action.
- [x] 3.6 Build blind return-receipt preparation and permission-aware approval-review views without embedding hidden manifest or stock values in HTML or client state.

## 4. Compare One Receipt to One Exact Dispatch Manifest

- [x] 4.1 Implement canonical order-independent comparison of product sets, exact integer base quantities, stock condition, and normalized distinct serial sets against exactly one approved return-dispatch source.
- [x] 4.2 Reject partial receipts, excess or unexpected observations, combined batches, duplicate serials, null/mismatched live serial IDs, and any substitute serial that differs from the approved source manifest.
- [x] 4.3 Keep comparison and source expectations server-authoritative and prevent any client-supplied manifest, match result, tax, stock, reservation, claim, or allocation value from influencing approval.

## 5. Approve Receipt and Complete Fulfillment Atomically

- [x] 5.1 Implement processing-time origin tax resolution under locked location/setting/tax rows: non-PKP maps to non-tax; PKP uses configured default then lowest-ID applicable fallback and fails if none exists.
- [x] 5.2 Persist immutable receipt tax ID/name/rate/provenance/setting/time snapshots before applying origin inventory and use the snapshot consistently for line allocation and serialized tax identity.
- [x] 5.3 Implement a locked approval executor with stable ordering across transfer, policy, source dispatch, receipt, obligations, active reservations, products/stocks, serials, claims, settings, and taxes.
- [x] 5.4 Add exact good/broken quantities to origin-classified stock, update global product totals without silent clamping or fractional conversion, and record canonical positive transfer transactions plus before/after snapshots.
- [x] 5.5 Revalidate each live serial and source movement serial/claim tuple, move exact serials to origin, apply origin tax identity, close movement custody, delete active claims, and record immutable before/after serial history.
- [x] 5.6 Close only the source batch's active reservations, increment each obligation by its exact reserved quantity using BCMath, reject over-fulfillment, and mark obligations fulfilled only at exact required quantity.
- [x] 5.7 Derive the header after effects: `RETURN_DISPATCHED` with any active reservation, `AWAITING_RETURN` with outstanding obligations but none active, otherwise `COMPLETED`; do not use header revision as the per-batch concurrency ledger.
- [x] 5.8 Commit inventory, transaction, serial, custody, claim, reservation, obligation, movement/history, tax snapshot, and header effects once under action-scoped idempotency with complete rollback on failure.

## 6. Focused Verification

- [x] 6.1 Add focused preparation/scanner tests for empty initial state, identical universal blindness, conversions, unexpected observations, in-transit serial resolution, empty confirmation, optimistic collisions, and blind error sanitization.
- [x] 6.2 Add focused lineage/lifecycle tests for one exact receipt per dispatch batch, concurrent receipts for different batches, immutable rejection, empty corrections, source/batch mismatch, and idempotent submission.
- [x] 6.3 Add focused comparison tests for exact and partial quantities, missing/excess products, combined batches, good/broken mismatch, exact substitute serial manifest, duplicate/mismatched serial IDs, and order independence.
- [x] 6.4 Add focused authorization/projection tests for original-side ownership, nested route binding, receive permissions, blind payload absence, privileged detail, Super Admin preparation blindness, and crafted protected inputs.
- [x] 6.5 Add focused approval tests across PKP/non-PKP origins, configured/fallback/missing tax, good/broken stock, exact transactions, serial tax/location/custody changes, live-state drift, rollback, and replay.
- [x] 6.6 Add focused multi-batch obligation/header tests proving out-of-order independent receipt, partial overall fulfillment, active-batch status precedence, return to `AWAITING_RETURN`, final `COMPLETED`, and concurrency-safe non-overfulfillment.
- [x] 6.7 Run only the new and directly affected stock-transfer tests with `composer test:fresh-sqlite` or focused `php artisan test` filters and record results; do not require a full-suite run.
