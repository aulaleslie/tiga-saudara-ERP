## Why

`product_prices.average_purchase_price` and `product_prices.last_purchase_price` can drift across settings because purchase approvals, purchase imports, product edits, and historical data paths do not all update every setting-scoped price row the same way. This makes future purchase defaults and reports depend on stale or missing setting-specific purchase cost data even though purchase cost should be global per product.

## What Changes

- Add an artisan normalization command that recalculates product purchase cost snapshots from historical received purchase data.
- Make the command dry-run by default, with writes only when explicitly requested through `--write`.
- Recalculate purchase cost only for stock-managed products with eligible purchase quantity.
- Treat purchases with status `RECEIVED` or `RECEIVED PARTIALLY` as eligible when they are not archived.
- Use approved received-note quantities where they exist for a purchase detail; otherwise use the purchase detail quantity so received import documents are included.
- Write the same recalculated `last_purchase_price` and `average_purchase_price` to every setting's `product_prices` row for each eligible product.
- Create missing `product_prices` rows for every setting while preserving or copying sale/tier/tax fields from an existing same-product row where possible.
- Preserve setting-specific sales prices and tier prices; this change only normalizes purchase price fields.
- Exclude purchase returns, non-stock-managed products, archived purchases, and products with no eligible historical cost quantity.

## Capabilities

### New Capabilities
- `product-purchase-price-normalization`: Defines the operator command that recalculates and synchronizes setting-scoped product purchase price snapshots from historical received purchases.

### Modified Capabilities

None.

## Impact

- Affected code: new Laravel artisan command under the application console command structure, `ProductPrice` reads/writes, purchase detail and received-note queries, and focused command tests.
- Affected data: `product_prices.last_purchase_price` and `product_prices.average_purchase_price` for eligible stock-managed products; missing `product_prices` rows may be created for settings that do not yet have one.
- Existing sales pricing behavior must be preserved: `sale_price`, `tier_1_price`, `tier_2_price`, `purchase_tax_id`, and `sale_tax_id` are not overwritten except when a missing row is initialized from an existing same-product row or zero defaults.
- No schema migration, no historical purchase rewrite, no product legacy price-field updates, and no changes to purchase import, purchase approval, sales, POS, or report behavior beyond the normalized data they already read.
