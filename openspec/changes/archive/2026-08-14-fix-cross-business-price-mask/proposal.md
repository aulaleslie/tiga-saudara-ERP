## Why

The cross-business product price page passes two-decimal model values such as `2500000.00` into a zero-precision Indonesian currency mask, which strips the decimal point and displays the value as `250000000`. This makes correct stored prices appear one hundred times larger and risks users submitting corrupted values after entering edit mode.

## What Changes

- Normalize cross-business product price values at the server-to-browser formatting boundary before applying the zero-decimal currency mask.
- Display sale, tier, last-purchase, and average-purchase prices at their correct Rupiah magnitude for every business row.
- Preserve the original numeric value through edit, cancel, validation-error restoration, and unchanged-form submission flows.
- Add regression coverage for decimal-cast product prices, including the reported `2500000.00` case.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `cross-business-product-price-management`: Require cross-business price fields to display and round-trip decimal-backed Rupiah values without changing their magnitude.

## Impact

- Affected UI: `Modules/Product/Resources/views/products/cross-business-prices.blade.php`.
- Affected backend boundary: cross-business product price presentation data may be normalized for the zero-decimal form without changing persisted `product_prices` precision.
- Affected tests: Product module cross-business price feature tests and focused rendering/round-trip regression coverage.
- No route, permission, database schema, dependency, or public API changes.
