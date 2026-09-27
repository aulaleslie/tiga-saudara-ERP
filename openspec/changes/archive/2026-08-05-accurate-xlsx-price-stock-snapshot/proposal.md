## Why

Accurate product exports already contain both owner-marked selling prices and the authoritative on-hand `Stock` quantity, but the existing XLSX workflow imports only prices. Stock must instead be loaded through a separate CSV snapshot flow with weaker product normalization and no DAIZU routing, making a single Accurate export incomplete and potentially inconsistent.

## What Changes

- Extend the existing Accurate XLSX sales-price snapshot workflow to stage and apply its `Stock` column as an owner-location stock snapshot alongside selling-tier prices.
- Resolve owner, target location, PKP/non-PKP stock buckets, and product identity through the established shared marker/DAIZU and normalized-name rules.
- Treat `Stock` as a replacement snapshot: set the resolved product's stock at the resolved owner's location to the imported signed value, including zero and negative values, and record the resulting `ADJ` ledger delta.
- Match existing products only; unmatched or ambiguous products are skipped or errored with no price, stock, product, unit, or transaction mutation.
- Make each successful row's price and stock effects atomic and expose both effects in batch-row audit details.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `product-sales-price-snapshot-import`: Accurate XLSX price snapshots also apply owner-scoped stock snapshots and report their inventory effects.
- `product-stock-owner-marker-import`: Accurate XLSX stock snapshots use the shared DAIZU-aware owner and normalized existing-product resolution rules without creating missing products.

## Impact

- Affects `Modules/Product` XLSX staging and price-snapshot processing, owner/location and stock adjustment handling, and product import batch detail guidance/rendering.
- Reuses `ProductImportBatch`, `ProductImportRow`, `ProductPrice`, `ProductStock`, inventory `Transaction`, locations, settings, and shared sales-import marker normalization.
- Requires focused feature coverage for DAIZU, marker routing, tax buckets, stock overwrites, product-name matching, failure isolation, and price/stock atomicity.
