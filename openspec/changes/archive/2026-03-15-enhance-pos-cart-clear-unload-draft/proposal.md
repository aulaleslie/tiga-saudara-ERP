## Why

Currently, the POS "Kosongkan Keranjang" (Clear Cart) action is blocked when a transaction is loaded from a DRAFT to prevent accidental data loss, except for Super Admins. However, users often need to pause a loaded transaction and start a new session without finalizing or deleting the draft. 

This change allows authorized users to clear their cart while a draft is loaded by automatically "unloading" the draft (reverting its status to DRAFT) and emptying the session cart, effectively opening a new session.

## What Changes

- **Update Clear Cart Logic**: Modify the `clear` cart action to support "unloading" of loaded transactions.
- **Permission Expansion**: Allow users with direct `pos.cart.clear` permission to unload drafts, not just Super Admins.
- **Status Reversion**: When a loaded transaction is cleared from the cart, its status in the database MUST revert from `LOADED` back to `DRAFT`.
- **Session Reset**: The session cart will be emptied and the link to the active transaction will be removed (`active_transaction_id` set to null).

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- `pos-supervised-cart-actions`: Update requirements for "Kosongkan Keranjang" to include unloading behavior and expanded permission access.

## Impact

- `Modules/Pos/Services/PosCartService.php`: Main entry point for clearing logic.
- `Modules/Pos/Services/PosTransactionService.php`: Backend logic for status reversion (unload).
- `Modules/Pos/Tests/Feature/POSTransactionEmptyBlockTest.php`: Existing tests will need updates as behavior changes.
