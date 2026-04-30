## Why

Bundle create/edit currently auto-fills item informational price inconsistently and can fall back to `0` when the selected product has no resolvable sale price in the active setting context. The current item price input also uses a numeric field that cannot support the requested UX of Indonesian currency formatting on blur and raw numeric editing on focus.

## What Changes

- Make bundle item informational price autofill resolve from `product_prices.sale_price` for the active `setting_id` (non-tier source of truth).
- Add explicit guard behavior for products without resolvable `sale_price` in the active setting, so missing pricing is visible instead of silently ambiguous.
- Update bundle item informational price input UX to:
- show formatted `Rp 10.000,00` on blur,
- show raw numeric `10000` on focus,
- submit canonical numeric values to backend validation/storage.
- Align create and edit bundle screens so pricing behavior is consistent across both flows.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `product-bundle-table-editor`: Change bundle item row behavior so selecting a product hydrates informational price from active-setting non-tier sale price and applies missing-price guard feedback.
- `product-bundle-price-configuration`: Clarify bundle item informational price capture contract to ensure persisted values come from canonical numeric payloads while UI formatting remains presentation-only.
- `currency-input-formatting`: Extend focus/blur currency interaction pattern to bundle item informational price field with Indonesian formatting (`Rp`, thousands separator `.`, decimal `,`).

## Impact

- Affected UI/component files:
- `app/Livewire/Product/BundleTable.php`
- `resources/views/livewire/product/bundle-table.blade.php`
- `Modules/Product/Resources/views/bundles/create.blade.php`
- `Modules/Product/Resources/views/bundles/edit.blade.php`
- Potentially affected picker/resolution path:
- `Modules/Product/Livewire/ProductSearchDropdown.php`
- Existing server-side validation in `Modules/Product/Http/Controllers/ProductBundleController.php` remains numeric and should receive clean numeric payloads.
- Test coverage additions expected in Product module feature/Livewire tests for create/edit bundle flows and currency field interaction.
