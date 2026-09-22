# Proposal

## Why

The product edit form currently disables the low-stock alert threshold as soon as a product has stock, even though changing the threshold does not mutate inventory structure. Products and their `product_stock_alert` value are shared globally, so the threshold should remain editable and should not be treated as a current-business setting.

## What Changes

- Allow authorized users to edit the low-stock alert threshold for stock-managed products even when stock already exists.
- Keep the threshold disabled when stock management is disabled.
- Make the form communicate that the threshold applies globally across businesses and locations.
- Persist the submitted threshold on the shared `products` row without applying a current-setting filter or creating per-setting copies.
- Preserve current-business scoping for product prices and preserve existing locks for inventory-structural fields.
- Verify the behavior with focused product-edit tests only; a full test-suite run is outside this change's verification scope.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `product-creation`: Extend the existing product stock-alert behavior to cover normal product editing and establish its global scope.

## Impact

- Product edit unit-configuration UI and explanatory copy.
- Existing product update request/controller path for the global `product_stock_alert` field.
- Focused Product module feature or Livewire tests.
- No schema, API, dependency, or per-setting price-model changes.
