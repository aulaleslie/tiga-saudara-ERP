## Why

Sales create and edit flows currently produce inconsistent line pricing depending on how the user adds a product or customer. The current behavior can leave the cart at the wrong base price, fail to reprice after customer creation, or mask purchase-only quick-add products as a sales pricing bug.

## What Changes

- Normalize sales cart pricing so newly added lines always derive their base and tier prices from the active setting's `product_prices` data.
- Reprice existing sales cart lines when a customer becomes selected, including when that customer is created from the quick-add modal.
- Align sales edit cart restoration with the same pricing source used for newly added lines instead of legacy product price columns.
- Make the sales product quick-add flow explicitly sales-oriented so users do not accidentally create purchase-only products and immediately add them to a sales cart with misleading pricing.
- Add clear requirements for the sales UI so wrong-price symptoms are surfaced as deterministic behavior rather than modal or session side effects.

## Capabilities

### New Capabilities
- `sale-cart-pricing`: Sales cart lines use setting-scoped price data consistently on create, edit, and customer repricing flows.
- `sale-product-quick-add`: Product quick-add from sales creates or validates sellable pricing before the product is inserted into the sales cart.

### Modified Capabilities
- None.

## Impact

- Affected Livewire components: `app/Livewire/Sale/ProductCart.php`, `app/Livewire/Sale/CreateForm.php`, `app/Livewire/Sale/EditForm.php`, `app/Livewire/Sale/SearchProduct.php`
- Affected modal and dropdown flows: `app/Livewire/Modules/Product/Modals/ProductQuickAddModal.php`, `app/Livewire/Modules/People/Modals/CustomerQuickAddModal.php`, `Modules/People/Livewire/CustomerSearchDropdown.php`
- Affected views: sales create/edit pages, sales cart view, product quick-add modal
- Affected data source: `product_prices` and setting-scoped pricing lookups during sales cart hydration and repricing
