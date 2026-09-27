## Why

Historical sales HPP imports correctly preserve authoritative cost snapshots on individual sale details, but they do not establish a current average cost in `product_prices` for future live POS and standard sales. After importing the source ledger from oldest to latest, operators need a controlled reconciliation step that seeds each product's current HPP from the latest authoritative imported sale snapshot.

## What Changes

- Add a dry-run-by-default Artisan command that identifies the latest successful imported sales HPP snapshot for each stock-managed product and cost bucket.
- Add explicit write mode to seed only `product_prices.average_purchase_price` from those latest snapshots.
- Report considered, skipped, unchanged, created, and updated product-price rows so operators can review the reconciliation before it writes.
- Preserve historical sale snapshots, `last_purchase_price`, selling prices, taxes, inventory, purchase documents, and the existing HPP import behavior.

## Capabilities

### New Capabilities

- `sales-hpp-average-cost-seeding`: Reconcile current product average purchase prices from the latest authoritative imported sales HPP snapshot per product cost bucket.

### Modified Capabilities

- `sales-hpp-snapshot-import`: Clarify that the import remains snapshot-only and that current-average seeding is an explicit, separate post-import operation.

## Impact

- Affected code: a new `Modules/Product` Artisan command and focused feature tests; existing `product_prices`, `sale_details`, `sales`, `products`, and settings/bucket resolution queries.
- No migration, external dependency, API, inventory movement, purchase receiving, POS checkout, or standard-sale posting change is planned.
- Future POS and standard sale snapshots will use the seeded `product_prices.average_purchase_price` through their existing snapshot service.
