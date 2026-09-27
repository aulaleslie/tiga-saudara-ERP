## Why

Sales and purchase imports can create stock-managed products with a legacy `unit_id` but without the `base_unit_id` used by the current Product CRUD unit configuration. When users later edit those products, the unit field may be locked/read-only while still empty, causing unrelated updates such as price changes to fail validation.

The same locked product edit UI still exposes the unit quick-create button, which lets users open a modal for a field they are not allowed to change.

## What Changes

- Ensure products created by Sales import resolve the imported unit into the Product CRUD canonical unit fields.
- Ensure products created by Purchase import resolve the imported unit into the Product CRUD canonical unit fields.
- Backfill existing affected products where a stock-managed product has `unit_id` populated but `base_unit_id` missing.
- Preserve legacy unit compatibility for older flows that still read `unit_id` and/or `product_unit`.
- Prevent quick-add actions from being available when the owning field is disabled or read-only, starting with the Product edit unit controls.
- Add focused automated coverage for sales import, purchase import, existing data repair, and disabled quick-add UI behavior.

## Capabilities

### New Capabilities
- `import-product-unit-alignment`: Defines unit integrity requirements for products created or repaired through sales and purchase import paths.

### Modified Capabilities
- `quick-add-form-management`: Disabled or read-only fields must not expose active quick-add controls for creating selectable values.

## Impact

- Affected code: `Modules/Sale/Services/SalesImportService.php`, `Modules/Purchase/Services/PurchaseImportService.php`, Product module migrations or repair command, Product edit unit configuration and dropdown Blade/Livewire components.
- Affected data: existing imported products with `stock_managed = true`, missing `base_unit_id`, and present `unit_id`.
- Affected tests: focused import service tests, Product edit request/Livewire or feature tests, and quick-add dropdown rendering tests.
- No external API, dependency, or breaking schema change is expected.
