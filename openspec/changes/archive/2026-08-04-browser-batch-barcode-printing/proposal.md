## Why

The existing Print Barcode screen can generate labels for only one product at a time and routes output through a PDF download, making routine multi-product label printing slow and unsuitable for the cashier's browser-and-driver-only workstation. Operators need to select several catalog products, print a complete batch in one browser print job, and show the correct price for the chosen business.

## What Changes

- Replace the single-product barcode-printing workflow with a batch selection workspace that supports multiple products, per-product quantities, removal, batch totals, and a complete-batch preview.
- Add a business selector that defaults to the active session setting and resolves every label price from that business's non-tier `product_prices.sale_price` value.
- Add a protected batch print endpoint that validates and re-loads the selected products, barcode data, and selected-business price rows before rendering one standalone HTML print document.
- Render one 55 mm × 40 mm label page per requested label, with product name, SKU (`product_code`), barcode SVG, barcode value, and formatted non-tier sale price.
- Invoke standard browser printing once per batch and provide a manual Print fallback, without printer bridges, browser extensions, device APIs, raw printer commands, or additional cashier-PC software.
- Add bounded per-product and total-batch safeguards, explicit validation errors for missing products, barcodes, supported symbology, or selected-business sale prices, and automated/manual acceptance coverage.

## Capabilities

### New Capabilities

- `browser-batch-barcode-printing`: Authorized users can prepare, preview, and print a multi-product barcode-label batch through one browser print action using selected-business non-tier pricing.

### Modified Capabilities

- None.

## Impact

- Affected code: Product module barcode route/controller/views; the existing barcode Livewire screen and product search/selection flow; Product price and business-context resolution reuse; focused feature and Livewire tests.
- Affected UI: the existing Produk → Print Barcode menu remains permissioned by `barcodes.print` but gains a business selector and batch workspace.
- External systems: Windows browser print dialog and the installed Blueprint ECO80BT Windows driver; physical media calibration remains outside application control.
- Dependencies: reuse installed `milon/barcode` SVG rendering; no new printer integration or cashier-PC software.
