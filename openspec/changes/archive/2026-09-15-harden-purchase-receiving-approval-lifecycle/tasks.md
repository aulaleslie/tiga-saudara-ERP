## 1. Purchase Lifecycle Foundation

- [x] 1.1 Add immutable Purchase status-transition audit persistence with Purchase, setting, optional receiving-note, old/new status, action/source, actor, and timestamp fields plus the required relationships and indexes.
- [x] 1.2 Implement a transactional Purchase lifecycle service that locks and reloads the Purchase and permits only `DRAFTED -> WAITING_APPROVAL`, `WAITING_APPROVAL -> APPROVED|REJECTED`, and `REJECTED -> DRAFTED` with action-specific authorization and audit creation.
- [x] 1.3 Route the generic Purchase status endpoint through the lifecycle service and return clear stale/invalid-transition responses while preserving existing redirect and notification behavior.

## 2. Stale Edit and Derived-Status Protection

- [x] 2.1 Remove client-controlled status persistence from the generic full Purchase edit path.
- [x] 2.2 Lock and reload the Purchase inside full-edit persistence, repeat edit-mode and receiving-evidence validation under the lock, and reject stale edits before any Purchase detail is deleted or recreated.
- [x] 2.3 Ensure `RECEIVED PARTIALLY` and `RECEIVED` can be written only by receiving approval or shortfall completion and cannot be reversed by generic lifecycle or edit requests.

## 3. Receiving Approval Hardening

- [x] 3.1 Refactor receiving approval to lock shared records in the agreed Purchase-first deterministic order and revalidate the pending note, eligible Purchase state, archive/source/setting/location constraints, and every receiving-detail relationship under those locks.
- [x] 3.2 Revalidate at approval that at least one receiving detail has a strictly positive canonical quantity while retaining zero-quantity rows as non-stock, non-progress evidence.
- [x] 3.3 Replace silent handling of missing Purchase details or invalid product/stock/serial state with domain conflicts that roll back the complete approval.
- [x] 3.4 Derive `RECEIVED` or `RECEIVED PARTIALLY` from cumulative approved quantities inside approval and create the receiving-sourced Purchase transition audit in the same transaction.
- [x] 3.5 Confirm duplicate and concurrent approval requests are idempotent across stock, tax buckets, product totals, approval-coupled prices, `BUY` transactions, serial links/history, receiving status, Purchase status, audits, and notifications.
- [x] 3.6 Defer any non-transactional or queued notification delivery until after commit while retaining database-backed notification state within the atomic approval boundary.

## 4. Focused Verification

- [x] 4.1 Add focused tests proving an all-zero receiving cannot be approved while a mixed positive/zero receiving approves and counts only positive quantities.
- [x] 4.2 Add focused tests proving full receipt derives `RECEIVED`, partial receipt derives `RECEIVED PARTIALLY`, and pending/rejected quantities do not contribute.
- [x] 4.3 Add focused endpoint/service tests proving stale or crafted lifecycle requests cannot leave or move a Purchase with positive approved receiving evidence to `APPROVED` or set receiving-derived statuses directly.
- [x] 4.4 Add focused deterministic race tests for full edit versus receiving approval and shortfall completion versus receiving approval, asserting consistent lock/revalidation outcomes and preserved Purchase details.
- [x] 4.5 Add focused idempotency and rollback tests proving duplicate approval and failures in relationship, stock, serial, history, notification, or audit processing produce no duplicate or partial effects.
- [x] 4.6 Run only the focused Purchase lifecycle and receiving-approval test classes or filters and resolve regressions within this change's scope; do not require the full application test suite.
