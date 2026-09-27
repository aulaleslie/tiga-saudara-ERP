## 1. Product discovery and scanner selection

- [x] 1.1 Extend the Print Barcode product-search query to include partial matches against `products.barcode` while preserving existing name, SKU, category, and brand matching.
- [x] 1.2 Change the search input's Enter handling to resolve a trimmed exact primary barcode, dispatch the existing product-selection event, and reset the input only after a match.
- [x] 1.3 Retain the existing result-list/no-results behavior for unmatched or non-exact Enter input, without resolving unit-conversion barcodes.

## 2. Verification

- [x] 2.1 Add focused Livewire coverage proving a typed primary barcode appears in Print Barcode search results and can be selected into the batch.
- [x] 2.2 Add focused Livewire coverage proving an exact primary barcode submitted with Enter adds the product, clears the input, and increments an existing batch row.
- [x] 2.3 Add focused coverage proving unmatched primary-barcode input and conversion-only barcode input do not add a product automatically.
- [x] 2.4 Run the relevant Product module feature/Livewire tests and record the result.
