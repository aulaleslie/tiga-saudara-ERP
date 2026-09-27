## 1. Permission Registration

- [x] 1.1 Add `'products.view_prices' => 'Lihat Harga'` to the `'Produk'` group in `app/Config/Permissions.php`

## 2. DataTable Column Definitions

- [x] 2.1 Add `addColumn('tier_1_price', ...)` and `addColumn('tier_2_price', ...)` in `ProductDataTable::dataTable()` following the existing `sale_price` pattern — format with `format_currency()`, null displays `-`
- [x] 2.2 Add two `Column::computed()` entries in `ProductDataTable::getColumns()` for `tier_1_price` (title "Jual Partai") and `tier_2_price` (title "Jual Reseller"), gated by `products.view_prices`, placed after the existing `sale_price` column
- [x] 2.3 Rename existing price column headers to abbreviated labels: "Beli Akhir", "Beli Rata²", "Jual"
- [x] 2.4 Replace all `Gate::allows('view_access_table_product')` checks with `Gate::allows('products.view_prices')` in `getColumns()`

## 3. Scroll and Frozen Column Layout

- [x] 3.1 Add `->parameters(['scrollX' => true, 'scrollY' => '70vh', 'scrollCollapse' => true])` to `ProductDataTable::html()`
- [x] 3.2 Add `@push('page_css')` block in `products/index.blade.php` with CSS `position: sticky` rules scoped to `#product-table` for freezing columns 0 (image, `left: 0`) and 1 (product code, `left: 50px`) with solid background and `z-index: 1`

## 4. Verification

- [x] 4.1 Verify the DataTable renders all 5 price columns with correct abbreviated headers when the user has `products.view_prices` permission
- [x] 4.2 Verify price columns are hidden when the user lacks `products.view_prices` permission
- [x] 4.3 Verify horizontal scroll freezes image + product code columns
- [x] 4.4 Verify vertical scroll freezes the table header row
- [x] 4.5 Verify null price values display `-` and never show values from another setting

## 5. Review Fixes

- [x] 5.1 Enforce `products.view_prices` permission on DataTables Ajax payload
  - Users without permission receive no price fields in JSON response
  - Authorized users receive all five price fields from active-setting `product_prices` row
  - Null/missing prices display as `-`

- [x] 5.2 Correct frozen-column CSS implementation
  - Use CSS variables for column widths: `--image-col-width: 80px`
  - Second column sticky offset uses variable: `left: var(--code-col-left)`
  - Opaque background colors (#fff light, #1f2937 dark)
  - No `background-color: inherit`

- [x] 5.3 Apply sticky styles to DataTables scrollY cloned header
  - Styles scoped to both `#product-table` body and `.dataTables_scrollHead #product-table` header
  - Columns remain aligned during scrollX + scrollY operations

- [x] 5.4 Add focused feature coverage tests
  - `ProductListDisplayTest` verifies Ajax response with/without permission
  - Null and missing price rows return `-`
  - Configuration assertions for scrollX, scrollY, CSS selectors/offsets
