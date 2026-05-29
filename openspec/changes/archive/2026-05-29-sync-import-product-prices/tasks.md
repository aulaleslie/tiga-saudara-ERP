## 1. Test Coverage

- [x] 1.1 Add focused purchase import coverage proving a successful import creates or updates `product_prices` rows for every setting with matching `last_purchase_price` and `average_purchase_price`.
- [x] 1.2 Add focused purchase import coverage proving purchase import does not overwrite existing `sale_price`, `tier_1_price`, or `tier_2_price`.
- [x] 1.3 Add focused sales import coverage proving a successful positive-price import creates or updates every setting with matching `sale_price`, `tier_1_price`, and `tier_2_price`.
- [x] 1.4 Add focused sales import coverage proving the latest processed positive sales row wins for repeated products.
- [x] 1.5 Add focused sales import coverage proving zero or blank final unit prices do not overwrite existing catalog sale or tier prices.
- [x] 1.6 Add focused duplicate-skip coverage proving skipped purchase and sales imports do not backfill or update product prices.

## 2. Purchase Import Implementation

- [x] 2.1 Add a purchase import helper that resolves all setting IDs and upserts purchase-price fields for a product across every setting.
- [x] 2.2 Replace single-setting `ProductPrice` purchase-price updates with the all-setting helper while preserving the existing final tax-included unit price and weighted average calculation.
- [x] 2.3 Ensure purchase import leaves `sale_price`, `tier_1_price`, and `tier_2_price` unchanged when updating purchase-price fields.
- [x] 2.4 Ensure duplicate purchase invoices still return before any product price synchronization.

## 3. Sales Import Implementation

- [x] 3.1 Add a sales import helper that resolves all setting IDs and upserts selling-price fields for a product across every setting.
- [x] 3.2 Replace single-setting `sale_price` updates with the all-setting helper for positive final tax-included unit prices.
- [x] 3.3 Update sales price synchronization so `sale_price`, `tier_1_price`, and `tier_2_price` are all overwritten with the same imported positive value.
- [x] 3.4 Ensure zero or blank final unit prices still create sale detail rows but skip catalog product price synchronization.
- [x] 3.5 Ensure duplicate sales invoices still return before any product price synchronization.

## 4. Verification

- [x] 4.1 Run the focused purchase import price synchronization tests.
- [x] 4.2 Run the focused sales import price synchronization tests.
- [x] 4.3 Run the nearest stable combined import test filter, such as `php artisan test --filter=Import`, if the focused tests pass.
- [x] 4.4 Run `openspec validate sync-import-product-prices --strict`.
