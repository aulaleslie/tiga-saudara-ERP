## Why

Existing products can have blank, arbitrary, or invalid barcodes, which prevents a consistent internal retail barcode standard. Operators need a safe, repeatable way to normalize all product base-unit barcodes to valid EAN-13 values without changing barcode-printing behavior.

## What Changes

- Add a Product module Artisan command that evaluates every existing product barcode and normalizes products that do not already hold a valid EAN-13 value.
- Preserve valid existing EAN-13 values; set their `product_barcode_symbology` to `EAN13`.
- Replace each blank, malformed, non-numeric, or checksum-invalid barcode with a generated, unique EAN-13 value whose first three digits are in the `200`–`299` range, then set its symbology to `EAN13`.
- Keep generated values unique across product base-unit and product-unit-conversion barcode namespaces, and keep the barcode identity registry consistent with product data.
- Provide a dry-run mode and clear outcome summary. This change does not alter barcode printing or rendering.

## Capabilities

### New Capabilities

- `product-ean13-barcode-generation`: Safely normalize existing product base-unit barcode data to unique, valid EAN-13 values through a console command.

### Modified Capabilities

- None.

## Impact

- Affected code: `Modules/Product/Console`, barcode identity/assignment support, Product module command registration, and focused Product module tests.
- Affected data: `products.barcode`, `products.product_barcode_symbology`, and `barcode_identities`; no migration is expected.
- No UI, printing, external API, or additional dependency changes.
