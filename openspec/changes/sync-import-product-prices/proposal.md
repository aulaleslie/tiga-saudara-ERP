## Why

Imported purchase and sales rows can create or update products without fully populating `product_prices` for every company setting. This leaves POS and Sales pricing paths vulnerable to `0` prices because they read the active setting's `product_prices` row and rely on populated base and tier sale prices.

## What Changes

- Purchase import will treat the final tax-included CSV unit price as purchase cost data only.
- Purchase import will update `last_purchase_price` and `average_purchase_price` for every setting's `product_prices` row for the imported product.
- Purchase import will not update `sale_price`, `tier_1_price`, or `tier_2_price`.
- Sales import will treat the final tax-included CSV unit price as catalog sale price data when the value is greater than zero.
- Sales import will overwrite `sale_price`, `tier_1_price`, and `tier_2_price` with the same imported value across every setting's `product_prices` row for the imported product.
- Sales import rows with zero or blank final unit price will keep their sale detail price as imported but will not overwrite catalog prices.
- Duplicate-skipped purchase and sales imports will not backfill or repair product prices.
- Historical rows already imported before this change will not be repaired by this change.

## Capabilities

### New Capabilities
- `import-product-price-sync`: Defines how purchase and sales imports synchronize imported unit prices into `product_prices` across settings and sale tiers.

### Modified Capabilities
- None.

## Impact

- Affected code:
  - `Modules/Purchase/Services/PurchaseImportService.php`
  - `Modules/Sale/Services/SalesImportService.php`
  - focused purchase and sales import tests
- Data model:
  - No schema changes expected.
  - Uses existing `product_prices` rows keyed by `product_id` and `setting_id`.
- Runtime behavior:
  - Future imports will populate price rows for all settings.
  - Sales import price synchronization will support POS and Sales tier pricing by keeping base, wholesaler, and reseller prices aligned.
