## 1. Backend Implementation

- [x] 1.1 Update `PosCartService::clear` in `Modules/Pos/Services/PosCartService.php` to bypass the `assertNotLastLineOfLoadedTransaction` check if the user is a Super Admin.
- [x] 1.2 Verify that `PosCartSessionStore::emptyCart` is correctly called, which resets `active_transaction_id` to `null`.

## 2. Frontend Fixes

- [x] 2.1 Update the `clearCartButton` event listener in `Modules/Pos/Resources/views/sell.blade.php` to set `originalText = 'Kosongkan Keranjang'`.
- [x] 2.2 Verify that `ApprovalManager.resetBtn` correctly restores the button state without undesirable side effects.

## 3. Verification

- [x] 3.1 **Super Admin Bypass**: Login as Super Admin, open a draft transaction, click "Kosongkan Keranjang", and verify the cart is cleared immediately.
- [x] 3.2 **UI Consistency**: Verify the button text returns specifically to "Kosongkan Keranjang" after a successful or failed clear action.
- [x] 3.3 **Staff Restriction**: Login as a non-Super Admin (e.g., Cashier), load a draft, and verify that "Kosongkan Keranjang" still returns a "Transaksi yang dimuat tidak dapat dikosongkan" error and does not clear the cart.
