## 1. Persist Inventory-Routing Snapshot

- [x] 1.1 Add an SQLite- and MySQL/MariaDB-compatible additive migration for a nullable `is_inventory_managed` (or equivalent) boolean on `dispatch_details`.
- [x] 1.2 Update `DispatchDetail` fillable/casts and `storeDispatch()` to persist the snapshot from the classification already resolved during submission (the aggregated per-line `is_inventory_managed` value).
- [x] 1.3 Test: submitting a dispatch persists the correct snapshot for a stock-managed detail and for a non-stock detail.

## 2. Make Dispatch Submission Atomic

- [x] 2.1 Move outstanding-demand calculation in `storeDispatch()` out of the pre-transaction `Validator::after()` closure and into the existing `DB::transaction()` block, locking the `Sale` row (`lockForUpdate()`) before recomputing remaining quantity per fulfillment key.
- [x] 2.2 Keep the pre-transaction validator for cheap early-reject feedback (shape/presence checks only); the authoritative accept/reject decision happens only under the lock.
- [x] 2.3 Test: two concurrent submissions requesting the same last outstanding quantity for one fulfillment key — only one creates a pending dispatch; the other fails with no dispatch header, detail, or notification created.
- [x] 2.4 Test: existing single-submission outstanding-quantity validation still passes (regression, not full suite — run only `DispatchApprovalTest`/`SaleController` dispatch tests).

## 3. Make Dispatch Approval Exactly Once

- [x] 3.1 In `approveDispatch()`, move the `isPending()` check inside `DB::transaction()` and lock the dispatch row first (`Dispatch::lockForUpdate()` or `$dispatch->lockForUpdate()->first()`), re-verifying pending status before any stock/serial/notification/status effect.
- [x] 3.2 Before mutating stock, compare each detail's persisted `is_inventory_managed` snapshot to the product's live `stock_managed` value; on mismatch, fail approval with an actionable classification-conflict message and leave the dispatch pending.
- [x] 3.3 For legacy details with a null snapshot (submitted before this change), infer `true` when persisted inventory-specific fields (location/serial allocation) unambiguously indicate stock movement, else infer `false`; reject approval when inference is ambiguous.
- [x] 3.4 Test: two concurrent approval requests targeting the same pending dispatch — exactly one applies stock/serial/notification/status effects; the other observes non-pending state and creates no additional effect.
- [x] 3.5 Test: a detail's product is reclassified stock-managed→non-stock (and separately non-stock→stock-managed) between submission and approval — approval fails with a conflict message and no side effect occurs.
- [x] 3.6 Test: legacy null-snapshot pending detail with unambiguous inventory evidence still approves correctly; ambiguous legacy detail is rejected with an actionable message.

## 4. Verification

- [x] 4.1 Run only the tests added/touched above plus existing `DispatchApprovalTest` and any existing `storeDispatch`/`approveDispatch` coverage in `Modules/Sale` — not the full suite.
- [x] 4.2 Document the migration as additive/nullable and rollback-safe in the PR description.
