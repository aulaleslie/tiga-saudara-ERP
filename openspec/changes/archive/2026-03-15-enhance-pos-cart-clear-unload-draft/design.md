## Context

The current POS implementation blocks clearing the cart if a transaction is loaded (except for Super Admins) to prevent accidental data loss. This forces staff to either complete the transaction or wait for a supervisor. To allow for flexible session management, we need a way to "unload" these transactions, making them available in the draft list again while freeing up the current POS session.

## Goals / Non-Goals

**Goals:**
- Enable authorized users to clear/unload drafts.
- Ensure loaded transactions revert to `DRAFT` status in the database upon clearing.
- Maintain data integrity by verifying ownership/permissions.

**Non-Goals:**
- Automatically saving changes before unloading (unloading reverts to the last SAVED state).
- Partial unloading or discarding specific lines from a loaded transaction during clear.

## Decisions

- **Entry Point: `PosCartService::clear`**: We will modify the existing `clear` service method. This keeps the frontend logic simple (the "Kosongkan Keranjang" button remains the single trigger).
- **Service Layer: `PosTransactionService::unload`**: We will introduce a new `unload` method in `PosTransactionService` to handle the transition from `LOADED` to `DRAFT`.
- **Permission: `pos.cart.clear`**: This permission will now double as the authority to unload a draft. Super Admins retain full access.

### Alternatives Considered
- **Explicit "Unload" Button**: Rejected for now to keep the UI clean. Users expect "Clear Cart" to reset the session regardless of how it was started.
- **Auto-save on Unload**: Rejected because users may want to discard experimental changes made after loading. They can "Save and New" if they want to persist changes before clearing.

## Risks / Trade-offs

- **Risk**: User mistakenly unloads a draft and loses unsaved local changes.
  - **Mitigation**: The UI already asks for confirmation for "Kosongkan Keranjang". The behavior of reverting to the last saved draft state is consistent with "opening a new session".
