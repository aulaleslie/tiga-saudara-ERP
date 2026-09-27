## 1. Schema and Models

- [x] 1.1 Add a MySQL/SQLite-compatible migration that gives transfers a non-null legacy `workflow_version = 1` without opting existing or new transfers into the movement workflow.
- [x] 1.2 Add movement-attempt, movement-line, movement-serial, and movement-history migrations with decimal base quantities, dependency links, actor/timestamp fields, idempotency scope, foreign keys, lookup indexes, and unique revision/line/serial constraints.
- [x] 1.3 Add movement entities, constants/casts, and transfer/line/serial/history relationships without changing existing transfer or serial query behavior.

## 2. Dormant Movement Domain

- [x] 2.1 Implement locked creation and shared-draft update behavior that validates transfer revision, type ordering, operational locations, stock condition, one-open-attempt rules, and optimistic concurrency.
- [x] 2.2 Implement canonical line persistence using inventory-compatible base-unit decimals, one line per product, and authoritative condition/precision validation.
- [x] 2.3 Implement normalized movement-serial persistence with duplicate prevention, line/product consistency, serialized quantity equality, and immutable submission snapshots.
- [x] 2.4 Implement submit, approve, reject, cancel, and superseding-correction transitions with immutable non-draft attempts, actor/reason history, one-approved-attempt enforcement, and action-scoped idempotency.
- [x] 2.5 Prove that dormant movement transitions do not mutate stock, inventory transactions, return obligations, transfer header status, live serial location, reservation, custody, or availability.

## 3. Permission Compatibility

- [x] 3.1 Register dispatch-create, dispatch-approval, receive-create, and receive-approval permissions in the centralized permission configuration.
- [x] 3.2 Add idempotent compatibility synchronization that grants both new dispatch permissions to legacy dispatch roles and both new receipt permissions to legacy receive roles without granting stock visibility or changing current route guards.

## 4. Focused Verification

- [x] 4.1 Add focused migration/model tests for workflow-version defaults, MySQL/SQLite-compatible constraints, relationships, decimal quantities, revision uniqueness, and normalized serial uniqueness.
- [x] 4.2 Add focused domain tests for valid/invalid transitions, immutable submissions, superseding revisions, dependency ordering, shared drafts, stale edits, competing attempts, and scoped idempotency.
- [x] 4.3 Add focused authorization tests for legacy permission mapping, preparation/approval separation, no implied `stockTransfers.view-system-stock`, and unchanged legacy production route checks.
- [x] 4.4 Add focused regression tests confirming existing transfer pages/payloads expose no dormant movement data and existing dispatch, receipt, stock, serial, and header lifecycle behavior remains unchanged.
