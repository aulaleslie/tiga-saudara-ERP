## Why

Cashiers currently cannot see products that match search keywords when stock is zero, which causes confusion during product lookup and creates false assumptions that a product does not exist. We need search visibility for out-of-stock items while still preventing invalid cart adds.

## What Changes

- Extend POS product search results to include stock-managed products with `available_qty = 0` when they match the query and are in allowed sales-location scope.
- Mark out-of-stock search results as non-selectable in the `Cari Produk` modal.
- Add clear visual treatment for out-of-stock results, including a `Stok Kosong` watermark/label.
- Ensure barcode/conversion auto-select behavior never auto-adds out-of-stock matches.
- Preserve server-side add-to-cart stock validation as authoritative safety.

## Capabilities

### New Capabilities
- `pos-product-search-stock-visibility`: POS keyword search SHALL return both in-stock and out-of-stock matches, and the search UI SHALL represent out-of-stock matches as visible but disabled.

### Modified Capabilities
- None.

## Impact

- Affected backend: `Modules/Pos/Services/PosProductSearchService.php` (search filtering and auto-select candidate rules).
- Affected frontend: `Modules/Pos/Resources/views/sell.blade.php` and `Modules/Pos/Resources/views/sell/css/styles.blade.php` (result-card rendering, disabled interaction, watermark styling, keyboard behavior).
- Affected tests: `Modules/Pos/Tests/Feature/POSProductSearchScanTest.php` plus frontend behavior coverage for disabled out-of-stock cards and auto-select guard.
- No new external dependencies or API endpoints; existing search endpoint contract is extended with out-of-stock visibility semantics.
