## 1. Fix the drawer layout

- [x] 1.1 In `resources/views/livewire/reports/sale-by-product-report.blade.php`, add `flex-shrink: 0` to the `offcanvas-header` element
- [x] 1.2 Add `flex-shrink: 0` to the `offcanvas-footer` element
- [x] 1.3 Add `min-height: 0` to the `offcanvas-body` element so its existing `overflow-y: auto` takes effect
- [x] 1.4 Verify in the browser that the Filter and Reset buttons stay visible and clickable with 60+ products selected
- [x] 1.5 Verify the drawer at a short viewport height, where footer collapse was most visible

## 2. Bound the selected-value display

- [x] 2.1 Give the product selected-pill container a `max-height` with `overflow-y: auto` so it scrolls independently
- [x] 2.2 Apply the same bounded treatment to the customer, category, and tag pill containers in this drawer
- [x] 2.3 Collapse the pill list to a summary count (e.g. "62 produk dipilih") once the selection exceeds a defined threshold
- [x] 2.4 Provide a control to expand the collapsed summary and a control to clear the whole selection
- [x] 2.5 Verify that selections at or below the threshold still render as individually removable pills

## 3. Tokenized search in filter lookups

- [x] 3.1 Add a private helper on `app/Livewire/Reports/SaleByProductReport.php` that trims the input, splits on whitespace, discards empty tokens, and applies one AND-ed `LIKE %token%` group per token
- [x] 3.2 Within each token group, OR the condition across all fields searched by that lookup
- [x] 3.3 Apply the helper to `updatedProductSearch()`, searching both `product_name` and `product_code`
- [x] 3.4 Apply the helper to `updatedCategorySearch()` against `category_name`
- [x] 3.5 Apply the helper to `updatedCustomerSearch()` against its existing `customer_name` and `contact_name` fields
- [x] 3.6 Keep the existing two-character minimum applied to the whole input, not per token, and keep `limit(10)` on the displayed options
- [x] 3.7 Manually verify that "alf in" returns ALFA INK products where the current code returns none

## 4. Product code in search results

- [x] 4.1 Add `product_code` to the columns selected by `updatedProductSearch()`
- [x] 4.2 Render the product code alongside the product name in the product option list
- [x] 4.3 Include the product code in the selected-pill label, keeping the pill readable at its bounded width
- [x] 4.4 Confirm `selectProduct()` still stores a label that renders correctly after a Livewire round trip

## 5. Select all matching results

- [x] 5.1 Add a `selectAllMatchingProducts()` action that re-runs the current tokenized product search without `limit(10)`, selecting only the id column
- [x] 5.2 Count matches before materialising, and enforce a selection ceiling (proposed: 500)
- [x] 5.3 Merge the resulting ids into `$productIds` without introducing duplicates and without dropping existing selections
- [x] 5.4 When the match count exceeds the ceiling, dispatch a message stating both the ceiling applied and the true total match count
- [x] 5.5 Make the action a no-op when the search term is below the minimum search length
- [x] 5.6 Resolve labels for the newly selected products so pills and the collapsed summary render correctly
- [x] 5.7 Add a "Pilih semua hasil" control to the product filter section, showing the number of matches where practical

## 6. Tests

- [x] 6.1 Add a test asserting a multi-token search matches a product whose name contains each token non-contiguously
- [x] 6.2 Add a test asserting a search where one token matches nothing returns no options
- [x] 6.3 Add a test asserting token order does not change the matched set
- [x] 6.4 Add a test asserting a product is found by `product_code`
- [x] 6.5 Add a test asserting a token matching the name and another matching the code together select the product
- [x] 6.6 Add a test asserting product options carry the product code
- [x] 6.7 Add a test asserting the minimum search length still suppresses options
- [x] 6.8 Add a test asserting select-all-matching selects products beyond the displayed limit
- [x] 6.9 Add a test asserting select-all-matching merges with an existing selection without duplicates
- [x] 6.10 Add a test asserting the ceiling truncates the selection and reports the true match count
- [x] 6.11 Add a test asserting select-all-matching is a no-op below the minimum search length
- [x] 6.12 Add a test asserting a report applied after bulk selection reflects every selected product

## 7. Verification

- [x] 7.1 Run `composer test:fresh-sqlite -- Modules/Reports/Tests/Feature/SaleByProductReportTest.php` and confirm the suite passes
- [x] 7.2 Manually verify the reported scenario end to end: search "alfa ink", select all matching, confirm the Filter button is reachable and the report applies
- [x] 7.3 Measure product search response time with the tokenized query on the largest data bucket and confirm no noticeable regression
- [x] 7.4 Confirm no database migration was added and that report aggregation, business scoping, snapshot hashing, and exports are unchanged
- [x] 7.5 Confirm the other 20 report drawers and the four sibling reports were left untouched, as scoped
