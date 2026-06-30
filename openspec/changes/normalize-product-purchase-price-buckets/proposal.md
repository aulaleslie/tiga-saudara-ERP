## Why

The historical product purchase price normalization command currently rebuilds product cost snapshots from all eligible purchases as one global average and can use tax-included unit prices. This overstates product costs for taxable purchases and blends historical purchase behavior for `CV TIGA NUSA COMPUTER` and `CV TOP IT INTERNUSA`, even though those companies need isolated historical baselines for normalization.

## What Changes

- Calculate normalized purchase cost from DPP unit cost for both `average_purchase_price` and `last_purchase_price`.
- Split historical normalization into three calculation buckets per product:
  - `CV TIGA NUSA COMPUTER` purchases.
  - `CV TOP IT INTERNUSA` purchases.
  - REST/global purchases from every other setting.
- Write the isolated Tiga Nusa result only to the `CV TIGA NUSA COMPUTER` product price row when that bucket has eligible cost history.
- Write the isolated Top IT result only to the `CV TOP IT INTERNUSA` product price row when that bucket has eligible cost history.
- Write the REST/global result to all non-special setting product price rows.
- If a special company bucket has no eligible cost history for a product, fall back to the REST/global result for that special company's product price row.
- Preserve current future/runtime purchase approval behavior where new approved purchases continue synchronizing average purchase price globally across settings.
- Preserve existing dry-run behavior, eligible purchase status rules, received quantity rules, sales metadata preservation, and missing row creation behavior.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `product-purchase-price-normalization`: Change historical normalization to use DPP cost snapshots and special-company bucketed averages with REST/global fallback.

## Impact

- Affected command: `Modules/Product/Console/NormalizeProductPurchasePricesCommand.php`.
- Affected tests: `Modules/Product/Tests/Feature/NormalizeProductPurchasePricesCommandTest.php` and any focused cost normalization coverage.
- Affected data: `product_prices.last_purchase_price` and `product_prices.average_purchase_price` values written by the normalization command.
- Not affected: purchase approval/runtime global average synchronization, sale cost snapshot behavior, product sales prices, tier prices, and tax metadata preservation.
