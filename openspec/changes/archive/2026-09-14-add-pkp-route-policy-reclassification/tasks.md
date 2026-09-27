## 1. Add Policy and Obligation Persistence

- [x] 1.1 Add migration-safe route-policy snapshot storage with transfer/revision uniqueness, route identities, PKP flags, classification, mandatory-return decision, condition, resolved tax snapshots, resolver provenance, and approval audit fields.
- [x] 1.2 Add return-obligation storage with transfer, source receipt movement, product, condition, required/returned/outstanding base quantities, lifecycle/audit fields, and uniqueness needed for idempotent full-product creation.
- [x] 1.3 Add receipt-line and movement-serial fields needed to retain source allocation, applied destination allocation, and before/after serial tax provenance without rewriting dispatch facts.
- [x] 1.4 Add Eloquent casts, fillables, relationships, constants, and database constraints for policy snapshots and obligations.

## 2. Snapshot Route and Tax Policy at Approval

- [x] 2.1 Implement a route-policy resolver for the five agreed same/cross-business PKP combinations and both stock conditions.
- [x] 2.2 Implement deterministic destination tax resolution using the configured applicable default followed by stable first-applicable fallback, including failure when a PKP destination has no applicable tax.
- [x] 2.3 Integrate locked, idempotent snapshot creation into transfer approval before workflow version `2` activation and persist descriptive tax values rather than relying on mutable joins.
- [x] 2.4 Make workflow-version eligibility use the committed snapshot prospectively for PKP-involved routes while preserving existing transfers without backfill or reinterpretation.
- [x] 2.5 Enforce tenant, transfer revision, location/business identity, and snapshot immutability at domain and route boundaries.

## 3. Reclassify Approved Forward Receipts

- [x] 3.1 Extend forward-receipt comparison/approval to require the exact approved policy snapshot and reject missing, stale, or inconsistent policy before mutation.
- [x] 3.2 Map exact receipt totals to `PRESERVE`, `TAX`, or `NON_TAX` destination buckets while retaining good/broken condition and exact source provenance.
- [x] 3.3 Persist source allocation, applied destination allocation, inventory transaction references, and destination before/after snapshots on receipt lines.
- [x] 3.4 Reclassify each exact serialized receipt using the snapshotted destination tax identity and record immutable old/new tax provenance alongside location/custody history.
- [x] 3.5 Keep preparation and blind-approval projections free of policy, tax, stock, allocation, obligation, and difference data unless the existing visibility permission authorizes it.

## 4. Create Full-Quantity Return Obligations Atomically

- [x] 4.1 Create one product/condition obligation for the full exact received quantity whenever the snapshot requires return, independent of source tax buckets and forward serial identities.
- [x] 4.2 Project mandatory-return receipts to `AWAITING_RETURN` and no-return receipts to `COMPLETED` without activating return-dispatch or return-receipt surfaces.
- [x] 4.3 Lock obligation identity and include classification, inventory, serial, custody, claims, transactions, obligations, histories, movement approval, and header projection in one rollback-safe idempotent transaction.
- [x] 4.4 Update authorized transfer detail/audit projections to expose snapshotted route and outstanding obligation state while preserving tenant scope and stock-visibility scrubbing.

## 5. Focused Verification

- [x] 5.1 Add migration/model tests for snapshot uniqueness and immutability, obligation constraints/casts/relationships, additive defaults, and no historical backfill.
- [x] 5.2 Add approval policy tests covering same-business PKP/non-PKP, cross-business non-PKP/non-PKP, PKP/non-PKP, non-PKP/PKP, and PKP/PKP routes plus post-approval setting drift.
- [x] 5.3 Add tax-resolution tests for configured default, deterministic first-applicable fallback, missing-tax rejection, and immutable ID/name/rate snapshots.
- [x] 5.4 Add forward-receipt inventory tests for tax/non-tax reclassification across good and broken stock, source/applied snapshots, global/location totals, transaction provenance, and crafted client input rejection.
- [x] 5.5 Add serialized tests for destination tax assignment or clearing, preserved old/new tax history, exact identity/custody behavior, and atomic rollback on serial drift or history failure.
- [x] 5.6 Add obligation tests for full quantities, mixed source buckets, serialized product-level identity, `AWAITING_RETURN` versus `COMPLETED`, duplicate approval idempotency, concurrency, and rollback.
- [x] 5.7 Add focused authorization/projection and route-activation tests proving no Delivery 8/9 operational return surface is exposed.
