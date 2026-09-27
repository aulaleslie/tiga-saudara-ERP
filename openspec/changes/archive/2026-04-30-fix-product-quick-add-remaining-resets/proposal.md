## Why

The `ProductQuickAddModal` in Livewire correctly resets its backend properties when reopened after a successful save. However, the frontend retains "dirty" values for `serial_number_required`, `product_stock_alert`, and `barcode` because Livewire's DOM diffing algorithm doesn't detect that these specific inputs need to be completely replaced. This causes users to unintentionally submit the previous product's serial number requirement and stock alert configurations for a new product.

This was partially fixed in a previous session for other fields (like product name and prices) by injecting the `$formResetVersion` into the `wire:key` attributes, forcing a hard re-render. This change applies the same proven fix to the remaining unkeyed inputs.

## What Changes

- Add `wire:key="...-{{ $formResetVersion }}"` cache-busting keys to the `serial_number_required`, `product_stock_alert`, and `barcode` inputs and containers in the `ProductQuickAddModal` view.
- Update the existing test suite (`ProductQuickAddResetTest`) to assert that `serial_number_required` and `product_stock_alert` reset to their default states (`false` and `null`).

## Capabilities

### New Capabilities
- `product-quick-add`: Ensure all modal fields correctly clear between creations.

### Modified Capabilities
- none

## Impact

- Modifies the Livewire view (`resources/views/livewire/modules/product/modals/product-quick-add-modal.blade.php`).
- Modifies the testing suite (`tests/Feature/Livewire/Product/ProductQuickAddResetTest.php`).
- Does not change backend models or DB schema.
