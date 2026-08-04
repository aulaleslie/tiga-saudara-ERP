## Why

The sales-HPP reconciliation command currently seeds only `average_purchase_price` from imported sales snapshots and preserves `last_purchase_price`. This leaves last purchase prices stale or empty even when eligible literal purchase history is available, particularly for businesses that receive price defaults from the three sales-import source businesses.

## What Changes

- Extend `product:seed-average-cost-from-sales-hpp` so its write mode also reconciles `product_prices.last_purchase_price` from literal received purchase details for the same product.
- Keep `average_purchase_price` selection and value unchanged: it continues to come from the latest eligible imported sales HPP snapshot.
- Calculate a literal purchase line's tax-inclusive, discount-excluded unit price from its stored line total plus line discount, divided by quantity.
- Select a business's own latest eligible literal purchase first; when absent, default to Perdana's latest eligible literal purchase for the product.
- Make Perdana the explicit default source for HPP and last-purchase-price fallback, rather than allowing any arbitrary non-special business in the REST bucket to supply defaults.
- Preserve an existing last purchase price when no own or Perdana literal purchase source is available; do not replace it with zero.
- Create missing target price rows only when the required HPP and literal-purchase source values can be resolved, while retaining the existing copying of same-product selling and tax metadata.

## Capabilities

### New Capabilities

<!-- None. -->

### Modified Capabilities

- `sales-hpp-average-cost-seeding`: Reconcile last purchase prices from received purchase history and make Perdana the explicit default source while preserving existing HPP snapshot seeding.

## Impact

- Affected command: `Modules/Product/Console/SeedAverageCostFromSalesHppCommand.php`.
- Affected persistence: `product_prices.last_purchase_price`; `average_purchase_price` semantics remain unchanged.
- Affected data sources: `purchase_details`, received-note approval history, purchases, settings, imported sale-detail HPP snapshots, and product price rows.
- Tests for the HPP seeding command will need coverage for tax-inclusive, discount-excluded literal purchase prices, Perdana fallback, missing sources, dry-run behavior, and missing price rows.
