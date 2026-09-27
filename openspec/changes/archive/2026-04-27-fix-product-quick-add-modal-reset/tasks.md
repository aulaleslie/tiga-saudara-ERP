## 1. Fix Product Quick Add Modal

- [x] 1.1 Update `app/Livewire/Modules/Product/Modals/ProductQuickAddModal.php` to ensure `resetForm()` clears all sales and purchase specific fields and increments `formResetVersion`.
- [x] 1.2 Update `resources/views/livewire/modules/product/modals/product-quick-add-modal.blade.php` to wrap Alpine.js currency inputs and other stateful DOM elements in `wire:key` bindings using `$formResetVersion`.
- [x] 1.3 Ensure unit conversion rows and their currency fields are also re-keyed to prevent stale display state.

## 2. Standardize Other Quick Add Modals

- [x] 2.1 Update `app/Livewire/Modules/People/Modals/SupplierQuickAddModal.php` to include a `$formResetVersion` property and increment it in `resetForm()`.
- [x] 2.2 Update `resources/views/livewire/modules/people/modals/supplier-quick-add-modal.blade.php` to use `wire:key` for form sections that benefit from a clean re-render.
- [x] 2.3 Apply the same version-reset pattern to `Modules/Setting/Livewire/Modals/TaxQuickAddModal.php` and its Blade view.
- [x] 2.4 Apply the same version-reset pattern to `Modules/Purchase/Livewire/Modals/PaymentTermQuickAddModal.php` and its Blade view.

## 3. Verification and UI Polish

- [x] 3.1 Verify "mini add" product flow on the Purchase Create and Edit pages.
- [x] 3.2 Verify sequential item entry works without manual input clearing.
- [x] 3.3 Verify similar behavior in the Sales module's create/edit pages.
- [x] 3.4 Confirm that validation errors are cleared alongside the form data upon reset.
