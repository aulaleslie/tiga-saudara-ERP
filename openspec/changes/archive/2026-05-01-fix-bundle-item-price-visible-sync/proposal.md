## Why

Bundle item `Harga Informasi Item` is correctly resolved from `product_prices.sale_price` by active session setting, but the visible input can remain empty after product selection because Alpine state is preserved across Livewire morphs. This causes user confusion and inconsistent UX even though submitted data is correct.

## What Changes

- Clarify bundle item pricing behavior so auto-loaded informational price must be visible immediately after product selection.
- Define deterministic UI synchronization requirements for the bundle item price field during Livewire updates.
- Keep existing persistence behavior unchanged: value remains sourced from `product_prices` for the current setting and remains user-editable before save.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `product-bundle-price-configuration`: tighten requirement for item-level informational price defaulting to include immediate visible rendering in the input after product selection.

## Impact

- Affected code: `resources/views/livewire/product/bundle-table.blade.php`, `app/Livewire/Product/BundleTable.php`, and related bundle create/edit views.
- No API contract changes.
- No database schema changes.
- UI behavior change only; should reduce operator input mistakes and repeated manual re-entry.
