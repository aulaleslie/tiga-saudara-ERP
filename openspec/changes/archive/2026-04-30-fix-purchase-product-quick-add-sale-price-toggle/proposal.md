## Why

The purchase create/edit pages let users mark a quick-added product as sellable, but the selling-price inputs do not appear after the `Saya Jual Barang Ini` checkbox is enabled. This breaks a core product setup path and leaves users unable to enter sale pricing from the purchase flow without leaving the modal.

## What Changes

- Update the shared product quick-add modal so the sale-pricing section reacts reliably when the sellable checkbox is toggled in purchase context.
- Preserve existing sales-page behavior where sale pricing stays visible and required for sales quick-add.
- Align the purchase/sales pricing section rendering strategy with the repo's existing quick-add form state-management patterns.
- Add regression coverage for the purchase-context toggle behavior so the sale-pricing fields remain visible when enabled and reset correctly when disabled or after submit.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `product-creation`: change the shared product quick-add requirements so purchase-context users can reveal and fill sale-pricing fields after marking a product as sellable.
- `quick-add-form-management`: change quick-add modal state-management requirements so sale-pricing controls remain reliably rendered and synchronized when toggle state changes inside the modal.

## Impact

- Affected code: `app/Livewire/Modules/Product/Modals/ProductQuickAddModal.php`, `resources/views/livewire/modules/product/modals/product-quick-add-modal.blade.php`, and related quick-add pricing partials/tests.
- Affected UX: purchase create/edit product quick-add modal, with shared modal behavior that must remain compatible with sales quick-add.
- Risk focus: Livewire/Alpine reactivity inside a Bootstrap/CoreUI-managed modal and regression coverage for context-specific rendering.
