## Why

The POS sell page currently has inconsistent behavior for its primary action buttons. The "Pilih Pembayaran" (Select Payment) button is correctly disabled when the cart is empty, has invalid prices, or missing serial numbers. However, the "Simpan dan Buka Baru" (Save and Open New) button remains enabled (if the user has permissions), allowing them to attempt saving invalid transactions, which leads to server-side errors or data inconsistency.

Syncing these behaviors ensures that "Simpan dan Buka Baru" is only available when the transaction is actually valid and ready to be processed, matching the same guards as the checkout flow.

## What Changes

I will modify the frontend JavaScript logic in the POS sell page to ensure the "Simpan dan Buka Baru" button's disabled state matches that of the "Pilih Pembayaran" button. Both buttons will be controlled by the same validation logic in the `renderCart` function.

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- `pos-save-transaction`: The activation behavior of the save-and-new action is now synchronized with standard checkout validation.

## Impact

- **Frontend**: `Modules/Pos/Resources/views/sell.blade.php` will be updated to include `saveDraftButton` in the UI sync logic.
- **User Experience**: Users will not be able to click "Simpan dan Buka Baru" if the transaction is invalid (e.g., empty cart or missing serial numbers).
