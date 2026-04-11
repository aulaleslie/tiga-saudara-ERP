## 1. Backend: ProductCart Component Preparation

- [x] 1.1 Add public property `$selectedBundle` (array) to `App\Livewire\Sale\ProductCart`.
- [x] 1.2 Implement `viewBundleDetails($rowId)` method to fetch bundle details from cart items.
- [x] 1.3 Add logic to dispatch an event (e.g., `open-bundle-modal`) from `viewBundleDetails`.

## 2. Frontend: Blade View Refactor

- [x] 2.1 Create `resources/views/livewire/sale/includes/bundle-details-modal.blade.php`.
- [x] 2.2 Include the new modal file at the bottom of `product-cart.blade.php`.
- [x] 2.3 Modify `product-cart.blade.php` to remove the inline `tr.collapse`.
- [x] 2.4 Update the \"Lihat Paket Penjualan\" trigger to a `button` with `wire:click`.

## 3. Verification

- [ ] 3.1 Verify modal opens with correct bundle data.
- [ ] 3.2 Verify cart table layout remains stable.
- [ ] 3.3 Verify mobile responsiveness of the modal table.
