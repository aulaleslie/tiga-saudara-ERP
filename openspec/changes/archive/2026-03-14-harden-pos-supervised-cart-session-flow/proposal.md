## Why

Current POS flow allows operational ambiguity across roles and terminals: cashier/floor staff restrictions are not consistently enforced in-cart, supervisor approval UX is partially mismatched, and session ownership can clash between users/terminals. This change is needed to harden transaction control so handoff from floor staff to cashier is safe, manager oversight is explicit, and transaction visibility is consistent without hidden completed records.

## What Changes

- Introduce role-driven cart action policy for Floor Staff, Cashier Staff, and Store Manager in POS sell flow.
- Standardize privileged cart mutations (`clear cart`, `remove line`, `reduce qty`) behind request/approve/execute flow for non-authorized users, with explicit `Periksa Persetujuan` polling and `Lanjutkan/Batalkan` completion step.
- Add manager approval queue behavior for approve/reject outcomes that deterministically updates pending requests.
- Extend price-change governance so only authorized manager-level permission can directly alter sales price; non-authorized staff must use approval flow.
- Enforce role-based terminal opening rules: Floor Staff and Store Manager can open without terminal selection, while Cashier Staff must select terminal.
- Strengthen POS session anti-clash behavior to avoid concurrent active-session conflicts across users and terminal assignments.
- Ensure POS transaction list defaults to showing all statuses (including completed) when no filter is applied, while keeping draft-only mutability.
- Preserve floor-to-cashier handoff via transaction number with clear editability boundaries.

## Capabilities

### New Capabilities
- `pos-supervised-cart-actions`: Asynchronous approval and execution lifecycle for restricted cart operations and price overrides, including manager queue decisions and user-facing state transitions.
- `pos-session-role-terminal-allocation`: Role-based terminal requirements and anti-clash active session ownership constraints for POS session opening and reuse.
- `pos-transaction-handoff-visibility`: Default all-status transaction listing and controlled draft mutability for floor-to-cashier continuation flow.

### Modified Capabilities
- `pos-sell-save-new`: Role-aware handoff expectations around `Simpan dan Buka Baru` while preserving existing validation-gated button behavior.
- `pos-transactions-list-loading`: Transaction loading behavior extended to include deterministic default filter semantics (all statuses when none selected).

## Impact

- Affected modules: `Modules/Pos` controllers, services, middleware, models, migrations, and sell/transactions/approval views.
- Affected permission usage: existing granular permission keys and role composition for Floor Staff, Cashier Staff, and Store Manager.
- Affected data constraints: active POS session uniqueness and approval request/token lifecycle handling.
- Affected UX/API contracts: cart approval endpoints, transaction list query defaults, and approval queue interactions.
