## Context

The POS system prevents clearing a cart that is currently linked to a persistent transaction (e.g., a "Loaded" draft) to protect against accidental loss of work. However, this restriction currently blocks Super Admins, who should have full control over the session environment.

Furthermore, the "Kosongkan Keranjang" button in the POS UI uses a hardcoded string `'Hapus Keranjang'` as its restore text, leading to inconsistent UI states after the action is triggered.

## Goals / Non-Goals

**Goals:**
- Enable Super Admins to clear the POS cart session regardless of whether a transaction is currently loaded.
- Ensure that clearing for a Super Admin automatically resets the `active_transaction_id` in the cart session (unloading it).
- Maintain UI consistency by fixing the button label restore logic.

**Non-Goals:**
- This design does not change the behavior for regular staff, who will still be blocked from clearing loaded transactions.
- We are not changing the underlying `PosCartService::clear` API signature, only its internal guarding logic.

## Decisions

### 1. Permission-Based Bypass
In `PosCartService::clear`, we will modify the call to `assertNotLastLineOfLoadedTransaction`. We will wrap it in a condition that checks if the user has the authority to bypass this constraint.
- **Rationale**: While we could check for "is super admin", checking for a specific permission or just allowing the bypass for users with `pos.cart.clear` who are also super admins (or a new permission `pos.cart.clear.bypass_loaded`) is safer. Given the current structure, a direct `user->isSuperAdmin()` or `user->can('pos.cart.clear')` with an additional check is appropriate.

### 2. UI String Synchronization
In `sell.blade.php`, the `originalText` variable in the `clearCartButton` event listener will be updated to `'Kosongkan Keranjang'` to match the actual initial state of the button.

## Risks / Trade-offs

- **Risk**: A Super Admin might accidentally clear a draft they intended to save.
- **Mitigation**: Standard confirmation dialogs (already present in the frontend) remain active for all users.
