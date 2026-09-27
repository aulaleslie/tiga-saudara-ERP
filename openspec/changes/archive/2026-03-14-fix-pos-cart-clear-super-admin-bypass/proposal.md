## Why

Super Admins currently face a restrictive business rule that prevents clearing the POS cart if a transaction is "Loaded" (e.g., editing a draft). This restriction is intended for regular staff to prevent accidental data loss of synchronized drafts, but Super Admins should have the authority to forcibly clear their session regardless of transaction state.

Additionally, a UI bug exists where the "Kosongkan Keranjang" button incorrectly flips its label to "Hapus Keranjang" after an action (failed or successful) due to a hardcoded string mismatch in the JavaScript event listener.

## What Changes

- **Backend**: Update `PosCartService::clear` to allow Super Admins to bypass the `TRANSACTION_EMPTY_BLOCKED` constraint. Clearing for a Super Admin will automatically unload the active transaction while emptying the cart session.
- **Frontend**: Fix the hardcoded `originalText` string in `sell.blade.php` to match the actual button label "Kosongkan Keranjang".

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- `pos-supervised-cart-actions`: Explicitly grant Super Admins the authority to clear the cart session even when a transaction is loaded.

## Impact

- `Modules/Pos/Resources/views/sell.blade.php`: Fixes UI label flickering/mismatch.
- `Modules/Pos/Services/PosCartService.php`: Relaxes constraints for Super Admin users.
