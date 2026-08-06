## Why

Print Barcode operators can only find products by name, SKU, category, or brand. A barcode scanner supplies the product's primary barcode, so scanning currently does not help the operator add labels to a batch.

## What Changes

- Allow the Print Barcode product search to match a product's primary barcode in addition to its existing search fields.
- Treat Enter on an exact primary-barcode value as a scanner-friendly selection action that adds the resolved product to the batch.
- Retain the existing typed-search suggestions and product-selection behavior for non-exact input.
- Defer product unit-conversion barcode lookup and printing of conversion barcode values.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `browser-batch-barcode-printing`: Extend product discovery in the Print Barcode workspace to support primary-barcode lookup and scanner Enter selection.

## Impact

- Affects `Modules/Product/Livewire/BarcodeProductSearch.php` and its Livewire Blade view.
- Affects Product module feature/Livewire coverage for Print Barcode search and batch-row selection.
- Reuses the existing `products.barcode` data; no schema, endpoint, printer-rendering, or dependency changes are required.
