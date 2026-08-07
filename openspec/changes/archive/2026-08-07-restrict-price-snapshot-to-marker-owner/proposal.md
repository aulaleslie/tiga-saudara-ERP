## Why

The combined Accurate sales-price and stock snapshot import currently copies an imported selling price into missing price rows for other businesses. This can make a business appear to have received a price that belongs only to Perdana, CV Top IT Internusa, or CV Tiga Nusa Computer.

## What Changes

- Restrict each successful sales-price-and-stock snapshot row to the single owner resolved from its product-name marker.
- Continue updating that owner's Sale Price, Tier 1 Price, Tier 2 Price, and stock snapshot at that owner's location.
- Stop creating or seeding `product_prices` rows for non-owner businesses as a side effect of this import.
- Retain the existing DAIZU ownership override for KEDELE, KEDELAI, and RAGI product names; it continues to take precedence over `*`, trailing ` TP`, and the Perdana fallback.
- Retain existing marker routing for non-DAIZU rows: leading `*` for CV Tiga Nusa Computer, trailing ` TP` for CV Top IT Internusa, and no marker for Perdana.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `product-sales-price-snapshot-import`: Scope selling-tier updates and price-row creation to the marker-resolved owner only.

## Impact

- Affects the sales-price-and-stock snapshot batch processor in `Modules/Product` and its feature tests.
- Changes the import's `product_prices` mutation scope only; it does not change product matching, stock adjustment, price fields, tax handling, or the workbook format.
