## 1. Database

- [x] 1.1 Create migration adding a plain BTREE index on `products.barcode` (additive; down-migration drops the index)

## 2. Business-scoping query logic

- [x] 2.1 Implement a local (component-scoped, not shared-trait) method returning available businesses: all `settings` for `hasRole('Super Admin')`, else `auth()->user()->settings()`, mirroring `BusinessSelector.php:23-39`
- [x] 2.2 Implement default-selected businesses = all businesses visible to the acting user

## 3. Stock aggregation query

- [x] 3.1 Implement a query service that, given a set of business (`setting_id`) IDs, joins `product_stocks` through `locations` to produce per-product, per-location Good (`quantity_tax + quantity_non_tax`) and Bad (`broken_quantity_tax + broken_quantity_non_tax`) quantities
- [x] 3.2 Implement collapsed (business-level) aggregation: sum Good/Bad across a business's locations
- [x] 3.3 Implement the tax/non-tax mismatch computation against `settings.is_pkp`, at both business-aggregated and per-location granularity (reference design.md Decision 4)
- [x] 3.4 Implement the availability filter (all / available / non-available) against the aggregated Good+Bad totals across visible businesses

## 4. Search

- [x] 4.1 Reuse/adapt `Product::scopeGlobalSearch` token logic for product name/code/category/brand search, unmodified in behavior
- [x] 4.2 Implement a separate exact-match lookup path against `products.barcode` and `product_serial_numbers.serial_number`, OR'd with the product-identity search results
- [x] 4.3 Implement serial-number match resolving to its owning product's row (no dialog auto-open, no highlight)

## 5. Category/brand filters

- [x] 5.1 Implement live-search category filter with implicit OR semantics, adapting `InventoryDetailReport`'s category filter pattern
- [x] 5.2 Implement live-search brand filter using the same interaction pattern

## 6. Livewire component and view

- [x] 6.1 Create the new Livewire component (mount-time `abort_unless` on `inventory.view_remaining_stock`), wiring business scope, search, filters, and pagination
- [x] 6.2 Create the blade view: business multi-select dropdown (reusing `business-source-selector.blade.php`), search box, category/brand/availability filter controls
- [x] 6.3 Implement the two-tier grouped table header (business group row with collapse/expand toggle; Good/Bad or per-location sub-header row) with a sticky first (product) column and horizontal scroll
- [x] 6.4 Implement per-cell tooltip rendering for tax/non-tax mismatch (collapsed and expanded)
- [x] 6.5 Implement the serial number button per Good/Bad cell, gated on `products.serial_number_required` and nonzero cell quantity

## 7. Serial number dialog

- [x] 7.1 Implement the dialog component/modal scoped to business + location(s) + condition, querying `product_serial_numbers` with the same "sellable" filter combination as `ProductSerialNumbersTable.php` (not broken, not in return, not dispatched, not returned) for Good cells, and `is_broken = true` for Bad cells
- [x] 7.2 Wire the dialog open action from the table's serial buttons

## 8. Excel export

- [x] 8.1 Create the Excel export class mirroring `ProfitLossReportExport`/`InventoryDetailReportExport` conventions, always rendering fully expanded per-location columns regardless of on-screen collapse state
- [x] 8.2 Ensure the export applies the same business-visibility scope, search, and filters as the current on-screen result set (pre-pagination)

## 9. Menu wiring

- [x] 9.1 Add the new report's route and menu entry, gated by `inventory.view_remaining_stock`

## 10. Focused verification

- [x] 10.1 Feature test: non-Super-Admin user sees only assigned businesses in filter options and table columns; Super Admin sees all
- [x] 10.2 Feature test: Good/Bad aggregation correctness across multiple locations for a single business (collapsed vs. expanded values reconcile)
- [x] 10.3 Feature test: tax/non-tax tooltip appears only when the mismatch condition is met, at both collapsed and expanded granularity, with seeded non-zero `broken_quantity`/`quantity_non_tax` data
- [x] 10.4 Feature test: serial dialog returns correct sellable/broken serials scoped to the clicked business+location+condition
- [x] 10.5 Feature test: product-identity multi-token search still matches order-independently (regression check against existing `Product::scopeGlobalSearch` behavior)
- [x] 10.6 Feature test: exact-match barcode/serial search returns expected row and does not match partial fragments
- [x] 10.7 Feature test: Excel export always shows fully expanded per-location columns regardless of on-screen collapse state, and respects applied filters/business scope
- [x] 10.8 Permission test: user without `inventory.view_remaining_stock` receives 403 on direct route access
