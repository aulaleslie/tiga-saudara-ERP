## Why

Terminal Harga currently treats a multi-word product search as one contiguous phrase in its fallback path, so typed searches such as `SAM GAL FO` can miss products that Product List finds. Operators expect Terminal Harga free-text search to behave like Product List while barcode, conversion-barcode, and serial scanning remain scanner-friendly.

## What Changes

- Change Terminal Harga free-text product search to tokenize typed terms by spaces and require each token to match at least one descriptive product field.
- Match free-text tokens against product name, product code, category name, or brand name, aligning with the Product List search expectation documented by the existing multi-word search behavior.
- Preserve existing whole-input scanning behavior for product barcode, conversion barcode, and serial number matches.
- Preserve active-setting `product_prices` filtering, customer-tier price display, pagination reset, scanner submit, and search refocus behavior.
- No breaking changes.

## Capabilities

### New Capabilities
- `terminal-harga-product-search`: Defines Terminal Harga product search behavior, including Product List style free-text matching and preserved scanner-code matching.

### Modified Capabilities
- None.

## Impact

- Affected code: `App\Livewire\PricePoint\Browser`.
- Affected tests: focused Terminal Harga Livewire/feature tests, likely extending `tests/Feature/PricePointBrowserCustomerTieringTest.php` or adding a dedicated Terminal Harga search test.
- No database schema, route, permission, dependency, or UI layout changes are expected.
