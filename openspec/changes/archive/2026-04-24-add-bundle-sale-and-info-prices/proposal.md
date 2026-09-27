## Why

Product bundle setup currently exposes `Harga Paket` from `product_bundles.price`, which represents the legacy add-on price and is easy to confuse with the final bundled selling price. Operators need a clearer bundle configuration surface that captures a modifiable final `Harga Jual Paket` and modifiable informational item prices without dropping or repurposing the existing legacy column.

## What Changes

- Add a new bundle-level `Harga Jual Paket` value that represents the final sale price for the parent product and selected bundle combined.
- Default `Harga Jual Paket` from the parent product's active-setting sale price when creating a bundle, while allowing the user to edit it.
- Hide the existing `Harga Paket` / `product_bundles.price` field from bundle create, edit, and product detail list UI without removing or repurposing the column.
- Add a new modifiable `Harga Informasi Item` value for each bundle item.
- Default each `Harga Informasi Item` from the selected bundle item product's active-setting sale price, while allowing the user to edit it.
- Show the new bundle sale price and item information prices in Product Bundle CRUD/list surfaces.
- Keep Sales and POS runtime pricing behavior unchanged in this change; those flows will be handled separately later.

## Capabilities

### New Capabilities
- `product-bundle-price-configuration`: Defines Product Bundle CRUD/list behavior for final bundle sale price configuration, legacy price hiding, and informational per-item prices.

### Modified Capabilities
- None.

## Impact

- Database: add new columns for bundle sale price and bundle item informational price while preserving existing `product_bundles.price` and `product_bundle_items.price` columns.
- Product Bundle CRUD: update create/edit form fields, Livewire bundle item table behavior, request validation, persistence, and product detail bundle listing.
- Product pricing lookup: use active `session('setting_id')` product price rows for default values.
- Sales/POS: no immediate pricing behavior change; existing code paths may continue reading legacy bundle price until a later change updates them.
- Tests: add or update Product module feature/component tests for defaults, editability, persistence, and legacy price hiding.
