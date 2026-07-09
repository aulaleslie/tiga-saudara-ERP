## 1. Search Coverage

- [x] 1.1 Add a focused Terminal Harga test proving multi-word free-text search finds a product when tokens partially match product name.
- [x] 1.2 Add a focused Terminal Harga test proving free-text tokens can match across product name, product code, category name, and brand name.
- [x] 1.3 Add focused Terminal Harga regression coverage proving product barcode, conversion barcode, and serial number searches still use the whole submitted input.
- [x] 1.4 Add coverage proving matching products without an active-setting `product_prices` row remain hidden.

## 2. Query Implementation

- [x] 2.1 Update `App\Livewire\PricePoint\Browser` product search to build a combined predicate with whole-input scanner-code matching and tokenized free-text matching.
- [x] 2.2 Ensure the free-text branch requires every whitespace token and lets each token match product name, product code, category name, or brand name.
- [x] 2.3 Ensure the scanner-code branch preserves whole-input matching for product barcode, unit conversion barcode, and serial number.
- [x] 2.4 Preserve existing active-setting product price subqueries, eager loading, contextual customer-tier price calculation, sort order, pagination, and `refocus-search` dispatch.

## 3. Verification

- [x] 3.1 Run the focused Terminal Harga search tests.
- [x] 3.2 Run the existing Terminal Harga customer-tiering tests to verify price display behavior did not regress.
- [x] 3.3 Manually review the resulting query logic for no changes to Product List, Sales, Purchase, POS cart, routes, migrations, or UI layout.
