## Why

The "mini add" (quick-add) modals in Purchase and Sale pages do not correctly reset their visual state after a successful creation. While the underlying Livewire state is reset, the Alpine.js-managed currency fields and other DOM elements often retain stale values. This prevents users from immediately adding another item without manual clearing, creating friction in high-volume entry workflows.

## What Changes

- Implement a comprehensive reset mechanism for all quick-add modals used in Purchase and Sale contexts (Product, Supplier, Tax, Payment Term).
- Ensure that Alpine.js-managed currency formatting components are correctly re-initialized or cleared when the form is reset.
- Standardize the "reset after success" behavior so that modals are immediately ready for a new entry upon reopening or if kept open for repeated additions.

## Capabilities

### New Capabilities
- `quick-add-form-management`: Defines standardized behavior for quick-add (mini-add) modal state management, ensuring full refresh of both Livewire and client-side (Alpine.js) states after successful operations or manual resets.

### Modified Capabilities
- `product-creation`: Update to require that product creation via quick-add flows correctly clears all inputs, including setting-scoped prices and unit conversions, for subsequent entries.
- `sale-product-quick-add`: Add requirement that the sales quick-add flow must allow sequential product additions by refreshing all sales-specific pricing fields after each successful cart insertion.

## Impact

- `App\Livewire\Modules\Product\Modals\ProductQuickAddModal`: Significant logic update for state resetting.
- `App\Livewire\Modules\People\Modals\SupplierQuickAddModal`: Addition of standardized reset logic.
- `Modules\Purchase\Livewire\Modals\PaymentTermQuickAddModal`: Standardized reset logic.
- `Modules\Setting\Livewire\Modals\TaxQuickAddModal`: Standardized reset logic.
- Various Blade templates providing the modal UI, particularly focusing on `x-data` components and `wire:key` usage.
