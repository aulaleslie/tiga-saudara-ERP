## Why

The Accurate price-and-stock snapshot currently updates only the resolved owner's price row. Products without price rows in other available businesses remain incomplete, even though the first imported selling price can provide a safe initialization value without overwriting established business-specific prices.

## What Changes

- When a valid Accurate snapshot row updates its resolved owner price, dynamically inspect every available business setting.
- Create a missing `product_prices` row for the same product in every other setting, using the imported owner selling price for all three selling tiers.
- Preserve all existing non-owner business price rows exactly as they are.
- Keep price-row seeding inside the existing owner-price and stock snapshot transaction.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `product-sales-price-snapshot-import`: A successful owner-specific snapshot price update initializes missing cross-business selling-tier rows while preserving existing business-specific prices.

## Impact

- Affects the combined Accurate XLSX price-and-stock snapshot processor and its focused feature tests in `Modules/Product`.
- Uses all existing `Setting` records and `ProductPrice` rows; does not change owner routing, product matching, stock ownership, or stock quantities in other businesses.
