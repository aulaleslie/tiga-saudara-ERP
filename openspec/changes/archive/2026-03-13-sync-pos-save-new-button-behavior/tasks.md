## 1. Frontend Implementation

- [x] 1.1 Update `renderCart` in `sell.blade.php` to include `saveDraftButton` in the activation logic.
- [x] 1.2 Ensure `saveDraftButton` is disabled if `canCheckout` is false.

## 2. Verification

- [x] 2.1 Verify that "Simpan dan Buka Baru" is disabled when the cart is empty.
- [x] 2.2 Verify that "Simpan dan Buka Baru" is disabled when serial numbers are missing for a serial-tracked product.
- [x] 2.3 Verify that "Simpan dan Buka Baru" is disabled when no customer is selected.
- [x] 2.4 Verify that "Simpan dan Buka Baru" is enabled when all conditions are met.
