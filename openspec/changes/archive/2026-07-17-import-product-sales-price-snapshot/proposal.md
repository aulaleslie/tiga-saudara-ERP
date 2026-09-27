## Why

The ERP needs a safe way to apply current selling prices from the Accurate product-export workbook without creating duplicate products or changing stock, purchasing data, or tax configuration. The source catalog has no usable product codes and contains owner markers and owner-specific prices, so the import must reuse established product-name normalization and owner-resolution behavior rather than relying on file order or raw names.

## What Changes

- Add a dedicated product sales-price snapshot upload for Accurate-style `.xlsx` workbooks containing `Name*` and `SellPrice` columns.
- Resolve product ownership from the established leading `*`, trailing `TP`, no-marker, and Daizu rules, and match the clean name through the shared import normalization rules.
- Update the resolved `product_prices` row so `sale_price`, `tier_1_price`, and `tier_2_price` all equal the positive imported `SellPrice`.
- Process valid rows independently while reporting zero-price, unmatched, ambiguous, structurally invalid, and failed rows without creating products or mutating unrelated data.
- Reuse product import batch monitoring with a distinct import type and row-level metadata showing the resolved product, owner, previous prices, and resulting prices.
- Provide a dedicated upload entry point and clear batch/detail presentation for this import type.

## Capabilities

### New Capabilities

- `product-sales-price-snapshot-import`: Upload, normalize, validate, apply, and audit owner-specific product selling prices from Accurate XLSX product exports.

### Modified Capabilities

None.

## Impact

- Product module routes, upload UI, controller validation, import batch type labels, row staging, queue processing, and batch detail presentation.
- Shared product marker and normalized-name matching behavior used by existing sales and product imports.
- `product_prices.sale_price`, `product_prices.tier_1_price`, and `product_prices.tier_2_price` for the owner setting resolved per successful row.
- Existing PhpSpreadsheet/Maatwebsite Excel dependencies will be used to read XLSX files; no new external integration is required.
- Focused feature and job tests will cover XLSX parsing, normalization, owner resolution, ambiguity handling, price safety, partial failure, permissions, and audit metadata.
