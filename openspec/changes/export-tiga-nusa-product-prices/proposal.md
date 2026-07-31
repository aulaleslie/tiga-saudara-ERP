## Why

CV TIGA NUSA COMPUTER needs a simple, shareable price list for every product without exposing other businesses' price data or requiring users to understand product codes. The existing barcode exporter establishes a familiar, safe console-export workflow, while the application already has the spreadsheet dependencies needed to produce a usable Excel workbook.

## What Changes

- Add a product console command that exports the CV TIGA NUSA COMPUTER price view to an Excel (`.xlsx`) file.
- Preserve the barcode exporter-style `--path` and `--force` workflow, including overwrite protection and a completion count.
- Resolve CV TIGA NUSA COMPUTER at runtime by its company name and export only that setting's sale, Tier 1, and Tier 2 price rows.
- Export one alphabetically sorted row per product with only the product name and the three price columns; products without a price row remain visible with blank price cells.
- Make the workbook operator-friendly with a title, frozen header row, filters, and numeric price formatting.

## Capabilities

### New Capabilities

- `tiga-nusa-product-price-export`: Export every product with CV TIGA NUSA COMPUTER's sale, Tier 1, and Tier 2 prices to a protected Excel workbook.

### Modified Capabilities

- None.

## Impact

- Affected code: Product module console commands and service-provider command registration; a new Excel export class; focused Product module feature tests.
- Data access: read-only joins across `products`, `product_prices`, and `settings`.
- Dependencies: uses the already-installed `maatwebsite/excel` and PhpSpreadsheet packages; no schema or API changes.
