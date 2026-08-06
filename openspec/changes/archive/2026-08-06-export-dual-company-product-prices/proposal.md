## Why

The current product price-list command exports only CV TIGA NUSA COMPUTER selling prices, leaving CV TOP IT INTERNUSA operators without an equivalent list and omitting purchase-cost context. A single workbook with company-isolated sheets will make the export useful for both businesses while preserving their independent pricing.

## What Changes

- Extend the existing product price-list XLSX export so sheet 1 remains CV TIGA NUSA COMPUTER and sheet 2 exports CV TOP IT INTERNUSA.
- Add last-purchase-price and average-purchase-price columns to each company sheet.
- Resolve a missing or zero average purchase price from the last purchase price, and a missing or zero last purchase price from the product purchase price.
- Keep selling and tier prices isolated to the `product_prices` record for the sheet's company.
- Fail without writing a workbook when either required company setting cannot be resolved uniquely.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `tiga-nusa-product-price-export`: Expand the existing company price-list export into a dual-company workbook and include resolved purchase-cost columns.

## Impact

- Affected code: `Modules/Product/Console/ExportTigaNusaPricesCommand.php` and `Modules/Product/Services/TigaNusaPriceExportService.php`.
- Affected verification: `Modules/Product/Tests/Feature/TigaNusaPriceExportCommandTest.php`.
- No schema, API, or dependency changes are expected.
