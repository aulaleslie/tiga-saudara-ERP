## Why

The dual-company price workbook is useful for reviewing and editing prices, but the existing import accepts only Accurate price-and-stock snapshots. Operators cannot round-trip the exported Tiga Nusa and Top IT workbook to update each company's base, Tier 1, and Tier 2 selling prices independently.

## What Changes

- Add a dedicated Product-module XLSX import for the existing dual-company price workbook.
- Update prices independently from the `Harga Jual`, `Harga Tier 1`, and `Harga Tier 2` columns on the CV TIGA NUSA COMPUTER and CV TOP IT INTERNUSA worksheets.
- Preserve an existing tier when its source cell is blank; accept a numeric zero as an explicit price update.
- Reject structurally invalid workbooks and ambiguous product matches without changing prices for affected rows.
- Keep this workflow separate from Accurate price-and-stock snapshots: it will not create products, alter stock, purchase costs, taxes, bundles, or conversion prices.

## Capabilities

### New Capabilities

- `dual-company-tier-price-import`: Upload, validate, process, and audit a dual-company price workbook while applying independent company-scoped selling-tier updates.

### Modified Capabilities

None.

## Impact

- Affected code: Product upload routes/controller/views, product import batch processing, and `product_prices` persistence.
- Affected verification: Product-module feature tests for upload authorization, workbook validation, per-sheet/company isolation, tier updates, blank-cell preservation, duplicate/conflict handling, and audit output.
- No schema migration, external API, or dependency change is expected; the workflow reuses the existing XLSX reader and import-batch monitoring UI.
