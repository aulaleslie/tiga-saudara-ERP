## Why

Kelola Harga Multi-Bisnis currently exposes only base-unit commercial prices, although conversion prices already exist separately for each business. Users need to compare and edit those conversion prices in the same page while preserving the shared unit/factor definition across businesses.

## What Changes

- Label the existing table Harga Satuan Dasar, including the base unit, and retain its current fields and behavior.
- Add Harga Satuan Konversi with businesses as rows and each conversion unit/factor as a column.
- Support independent conversion prices, decimal input, page-level edit/cancel/save, and an explicit apply-to-all action restricted to the same conversion.
- Save both sections atomically and reject stale prices or conversion definitions.
- Distinguish missing conversion prices from explicit zero and preserve per-business sales/purchase enablement flags.
- Preserve product-edit rules: shared unit/factor updates and deletion affect all businesses; existing conversion price edits affect only the active business; new conversions seed the initial price to all businesses. Changing factors never automatically recalculates other businesses' prices.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `cross-business-product-price-management`: Add conversion price management and preserve the shared conversion lifecycle.
- `cross-business-price-column-copy`: Extend explicit cross-business copying to individual conversion columns.

## Impact

- Product cross-business price Blade view, controller, request validation, and CrossBusinessPriceService.
- Existing ProductUnitConversion and ProductUnitConversionPrice storage; no new schema or migration expected.
- Focused backend, rendering, and product-edit regression verification. Browser testing is human-only; no full-suite test plan.
- Existing routes and permission remain in use. Conversion tiers, separate conversion purchase prices, and conversion structure editing on this page are outside scope.
