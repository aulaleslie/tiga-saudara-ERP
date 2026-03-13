## 1. Role Policy Baseline

- [x] 1.1 Define and document POS action-to-permission mapping for Floor Staff, Cashier Staff, and Store Manager.
- [x] 1.2 Enforce server-side role-aware authorization for clear cart, remove item, reduce quantity, payment, and price override actions.
- [x] 1.3 Align POS sell UI capability flags with backend authorization outcomes (no UI-only trust).

## 2. Supervisory Approval Lifecycle

- [x] 2.1 Refactor restricted actions (`clear`, `remove`, `reduce`) to always return deterministic approval states (`pending`, `approved`, `rejected`) for non-authorized users.
- [x] 2.2 Add/align explicit approval status check endpoint contract so `Periksa Persetujuan` can be retried without side effects.
- [x] 2.3 Implement one-time token consumption on `Lanjutkan` and ensure `Batalkan` exits without cart mutation.
- [x] 2.4 Extend asynchronous approval flow to sales-price override requests for non-authorized users.
- [x] 2.5 Ensure supervisor queue approve/reject actions deterministically resolve pending requests and expose rejection context.

## 3. Session And Terminal Anti-Clash

- [x] 3.1 Implement role-based terminal opening rules (Floor/Manager optional, Cashier required).
- [x] 3.2 Enforce one active POS session per `(setting, user)` invariant at service and persistence layers.
- [x] 3.3 Enforce one active POS session per `(setting, terminal)` for terminal-bound sessions.
- [x] 3.4 Add conflict response handling for session ownership collisions with actionable user messages.

## 4. Draft Handoff And Transaction Visibility

- [x] 4.1 Ensure `Simpan dan Buka Baru` persists draft handoff records and clears active cart for next transaction.
- [x] 4.2 Guarantee cashier can reopen draft by transaction number and continue flow to payment.
- [x] 4.3 Enforce draft-only mutability and prevent cart mutation on completed transactions.
- [x] 4.4 Update transaction list query defaults so empty status filter returns all statuses including completed.
- [x] 4.5 Ensure checkout paths create or update POS transaction history so completed sales always appear in list.

## 5. POS UI Action-State Consistency

- [x] 5.1 Implement button-state transitions for restricted actions: request -> check approval -> lanjutkan/batalkan.
- [x] 5.2 Add reason dialog support (optional input) for restricted action requests.
- [x] 5.3 Surface approval queue navigation for users with supervisor approval permission.
- [x] 5.4 Standardize pending/approved/rejected notifications so users can safely retry checks.

## 6. Validation, Regression, And Rollout

- [x] 6.1 Add feature tests for role matrix enforcement across Floor Staff, Cashier Staff, and Store Manager.
- [x] 6.2 Add integration tests for approval request lifecycle, token single-use behavior, and rejection outcomes.
- [x] 6.3 Add tests for session conflict invariants across user and terminal combinations.
- [x] 6.4 Add tests for default transactions list behavior (no filter includes completed) and draft-only editability.
- [x] 6.5 Run targeted POS regression suite and document rollout/rollback notes for operations.
