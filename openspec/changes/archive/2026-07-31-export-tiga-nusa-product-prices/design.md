## Context

Product commercial prices are stored in `product_prices`, uniquely keyed by product and business setting. `products` still carries legacy price columns, but current price accessors and product screens use the setting-specific price rows. CV TIGA NUSA COMPUTER needs a self-service spreadsheet that follows the established `product:export-barcodes` command conventions: an optional path, overwrite protection, and an operator-visible row count.

The application already includes Laravel Excel and PhpSpreadsheet. The export is read-only and must never reveal prices belonging to a different setting.

## Goals / Non-Goals

**Goals:**

- Produce an `.xlsx` price list for CV TIGA NUSA COMPUTER only.
- Present a simple, non-technical worksheet sorted by product name, with product name, sale price, Tier 1 price, and Tier 2 price.
- Include products even when their Tiga Nusa price row is absent, using blank price cells to expose incomplete setup.
- Preserve the barcode exporter’s operator workflow for default/custom paths and overwrite confirmation.

**Non-Goals:**

- Export prices for selectable or all business settings.
- Modify prices, product records, price rows, or schema.
- Add product code, barcode, category, brand, unit, purchase cost, tax, or stock data to the workbook.
- Provide a browser UI, scheduled export, CSV alternative, import, or price synchronization.

## Decisions

### Scope prices by a runtime-resolved setting

The command will look up exactly one `settings` record by the exact company name `CV TIGA NUSA COMPUTER`, then constrain the price join to that setting ID. It will fail before creating a file when the lookup finds zero or more than one setting.

This prevents a stale hard-coded setting ID from exporting the wrong business after environment reseeding. A `--setting` option was considered but rejected because the requested export has one fixed owner and selectable scope makes cross-business disclosure easier.

### Use products as the export base and a left join for prices

The row source will be `products`, left-joined to the resolved setting’s `product_prices` row by product ID and setting ID, ordered by `products.product_name`. This guarantees one row per product under the unique product-price constraint and preserves products that are not yet priced for Tiga Nusa.

Starting from `product_prices` was considered but would silently omit unpriced products. Reading legacy price columns from `products` was rejected because those values are not the current setting-scoped price source.

### Create a native Excel workbook

An export class backed by Laravel Excel/PhpSpreadsheet will write an Xlsx workbook to the destination path. The worksheet will have the title `CV TIGA NUSA COMPUTER`, a price-list subtitle, an export timestamp, a bold header, a frozen header row, an auto-filter, and numeric price cells formatted for spreadsheet use. Missing price values will remain blank rather than being converted to zero.

CSV was considered because the barcode command uses it, but Xlsx is selected because the request explicitly calls for Excel and it supports formatting, filters, and frozen headers without compromising numeric values.

### Match the existing command’s file-safety behavior

The command will offer `--path` and `--force`. With no path, it will write to `storage/app/product_prices_tiga_nusa_export.xlsx`. If the resolved target exists, it will ask for confirmation unless `--force` is supplied; declining will exit without changing the existing file. It will report the destination and count of exported product rows.

## Risks / Trade-offs

- [Setting name is renamed or duplicated] → Resolve by exact name at each run and terminate with an actionable error instead of exporting uncertain data.
- [Large catalogs increase workbook memory/time] → Use a query-backed/chunked export approach supported by the existing Excel library and cover representative catalog sizes in verification.
- [Blank price cells might be mistaken for a zero price] → Keep blanks intentionally and state this behavior in the command completion message or workbook subtitle if needed.
- [A user supplies a path without an `.xlsx` suffix] → Treat the selected writer/extension consistently and document that the default and supported artifact is `.xlsx`.

## Migration Plan

1. Deploy the new Product-module command and its Excel export class with no migration or data change.
2. Run the command in a non-production environment and open the workbook to validate the resolved Tiga Nusa data and presentation.
3. Run in production using the default path or an explicitly supplied approved destination.
4. Roll back by removing the new command registration and classes; generated workbooks are external artifacts and no database rollback is required.

## Open Questions

- None. The workbook columns, owner scope, sorting, missing-price representation, and command UX have been decided.
