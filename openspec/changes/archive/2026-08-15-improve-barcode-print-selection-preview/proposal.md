## Why

Barcode-printing operators cannot see a product's barcode or selected-business sale price while searching or composing a batch. They must request the separate expanded preview before they can confirm that they selected the intended product and that its label information is printable, which slows scanner-heavy label preparation and delays actionable data-quality feedback.

## What Changes

- Show each product's primary barcode and authorized selected-business non-tier sale price in Print Barcode search suggestions.
- Update the Print Barcode search guidance to make name, SKU, and primary-barcode input discoverable while preserving exact-primary-barcode Enter behavior and excluding conversion-barcode resolution.
- Add a rightmost label-preview column to each selected-product row, showing one compact representation of the physical label regardless of requested quantity.
- Keep selected-row previews synchronized with the authorized selected business and current product barcode/price data.
- Show actionable inline preview states when a selected product lacks a printable barcode, has an invalid explicitly EAN-13 barcode, or lacks a selected-business sale price, while retaining existing server-side preview and print validation as the authority.
- Preserve the existing expanded batch preview, quantity merging, batch limits, print endpoint, label layout, and browser-print behavior.
- Add focused Product-module feature and Livewire coverage only; full-suite execution is outside this change's verification scope.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `browser-batch-barcode-printing`: Extend product discovery and batch preparation requirements so operators can see primary barcode and authorized selected-business price in suggestions and inspect an immediate per-product label preview while composing the batch.

## Impact

- Affected UI: Product module Print Barcode search suggestions and selected-product batch table.
- Affected code: `BarcodeProductSearch`, `BarcodeBatchWorkspace`, their Blade views, and shared barcode-label payload construction where needed to keep previews consistent with printing.
- Affected data access: constrained `product_prices.sale_price` reads for the resolved selected business; no schema or stored-data changes.
- Security: existing `barcodes.print` permission and document-business authorization rules remain mandatory before exposing business-specific price data.
- Tests: focused updates to `Modules/Product/Tests/Feature/BrowserBatchBarcodePrintingTest.php` and directly related Product-module tests if required.
- Dependencies and external systems: no new packages, APIs, printer integrations, or device capabilities.
