## Why

The cross-business product price page rounds database-backed decimal prices to whole Rupiah and cannot safely accept locale-formatted fractional input, even though `product_prices` stores two decimal places. Its money-mask handlers can also mutate inputs before `Ubah` is activated, violating the page's required read-only view state.

## What Changes

- Display and edit sales, tier 1, tier 2, last purchase, and displayed average purchase prices with Indonesian thousands and decimal separators while preserving up to two decimal places.
- Normalize locale-formatted editable values to canonical decimal strings before submission and preserve decimals through dirty detection, apply-to-all, cancellation, validation restoration, and unchanged saves.
- Prevent all keyboard, deletion, paste, and mask-driven mutations of commercial price values until the user activates `Ubah`.
- Keep average purchase price non-editable in every page state and preserve existing atomic save, optimistic locking, tax, and average-cost rules.
- Replace whole-number masking expectations with focused decimal round-trip and pre-edit immutability coverage.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `cross-business-product-price-management`: Change price presentation and input from zero-decimal rounding to two-decimal-safe locale formatting, and strengthen the initial view state so client-side masking cannot mutate values before edit mode.

## Impact

- Affected UI and JavaScript: `Modules/Product/Resources/views/products/cross-business-prices.blade.php` and the existing `jquery-mask-money` integration.
- Affected request boundary: locale-formatted price values must be converted to canonical numeric values accepted by `CrossBusinessPriceUpdateRequest`.
- Affected tests: focused Product module cross-business price rendering, validation, round-trip, cancel, apply-to-all, and view-state interaction coverage.
- No route, permission, database schema, external dependency, or public API changes.
