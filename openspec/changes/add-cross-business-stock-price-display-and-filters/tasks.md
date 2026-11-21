# Tasks

## 1. Applied filters and selection behavior

- [ ] 1.1 Extend the filter DTO/query with normalized product IDs and cycle-safe descendant category matching; add focused fixtures proving OR within selectors, AND across category/brand/product/search/availability, no duplicate rows, and global products visible across selected authorized businesses.
- [ ] 1.2 Implement canonical draft/applied criteria and Apply/Cancel/Reset with all-authorized-business initial defaults, page reset, applied summary, and pending-change export guard; verify Livewire tests cover initial data, empty/forged business selection, Cancel restoration, Reset defaults, and unchanged results/columns/serial marker until Apply.
- [ ] 1.3 Adopt Sales by Product checkbox selection for category/brand/product, persistent token search, existing result caps/overflow feedback and bulk-selection limits where applicable, and chips/counts/remove/clear above inputs; verify targeted selector tests and rendered chip order/scoped Bootstrap 4 checkbox styles without modifying unrelated selectors or adding Alpine runtimes.
- [ ] 1.4 Separate bounded loaded page data from draft/presentation requests and revalidate scope before reuse; verify one loader invocation for Apply from page one and page two, one for pagination, no stock/purchase aggregate query during draft typing or formatting, and no stale unauthorized data after scope revocation.
- [ ] 1.5 Document the filter/Apply/Cancel/Reset behavior and preserved general barcode/serial search in a report guide; verify examples match the passing combined-filter and draft-state fixtures.

## 2. Optional authorized business prices

- [ ] 2.1 Add bulk current-business price loading for page/batch products using average_purchase_price, sale_price, tier_1_price, tier_2_price; verify distinct businesses, missing rows/null fields, saved zero, decimal magnitude, no legacy/other-business fallback, and bounded query growth.
- [ ] 2.2 Enforce inventory.view_remaining_stock plus products.view_prices for all price responses/actions and retain stock-only access; verify manipulated toggles/export values, unassigned business IDs, revoked permissions, and that unauthorized rendered content/component state contains no price fields.
- [ ] 2.3 Add Tampilkan Harga default-off immediate presentation and base-unit Satuan with four price columns once per business; verify toggle does not apply draft criteria, expanded locations do not duplicate prices, quantity conversion leaves prices unchanged, empty states/colspans align, and no stock/purchase aggregates run solely for presentation changes.
- [ ] 2.4 Document current base-unit Harga Modal versus historical HPP, zero versus unavailable, permission requirements, and price-toggle behavior; verify the documented four-field mapping matches implementation and fixture values.

## 3. Export contents and serialized correctness

- [ ] 3.1 Add validated Stok saja (default), Harga saja, and Stok + Harga export choices independent of the display toggle; verify all use applied criteria/business scope, pending criteria block every mode, invalid modes fail validation, stock-only remains permitted without price permission, and price-bearing modes reject unauthorized requests.
- [ ] 3.2 Extend generator/header metadata for all three contents while preserving fully expanded stock locations and global/business in-delivery values; verify serialized XLSX headings, merge ranges, dimensions and row widths for multiple/no-location businesses, base Satuan in price-bearing modes, no location/stock columns for Harga saja, and four prices once per business for combined export.
- [ ] 3.3 Preserve numeric price precision, strict zero/null distinction, quantity display modes, and all-page filtering in exports; verify actual XLSX cells for zero/missing/2500000.00, selected-business differences, more than one page/batch, and price-only availability filtering without unnecessary stock/purchase data materialization.
- [ ] 3.4 Add export examples to the report guide, including hidden screen prices with Harga saja and stock availability affecting price-only results; verify examples against passing serialized fixtures.

## 4. Focused integration verification

- [ ] 4.1 Run `php artisan test tests/Feature/Reports/CrossBusinessStockInventoryFeatureTest.php` plus explicitly named new filter/price/export suites and relevant category-tree tests; record exact commands, pass counts, and any targeted fixture changes required by explicit Apply. Preserve Good/Bad, tax tooltips, serial dialogs/markers, business scope, in-delivery and quantity conversion behavior. Do not run the full suite unless an identified regression requires it.
- [ ] 4.2 Record a read-only representative local MySQL check with all authorized businesses and a narrow category/product selection: compare row/quantity results with stock-only baseline, verify prices against product_prices, record timings/query counts for Apply from page two, draft search, presentation toggles, and multi-batch export, and confirm no report query per product/business/cell. Use an isolated fixture database for any test that writes data.
- [ ] 4.3 Have a human developer record one focused browser/download check for checkbox alignment/chips, Apply/Cancel/Reset, barcode/serial marker stability, default-hidden prices, once-per-business layout during expansion, and the three Excel export choices. Leave unavailable checks pending; passing PHP tests do not complete browser verification.
- [ ] 4.4 Run `openspec validate add-cross-business-stock-price-display-and-filters --strict`, review documentation and spec/test correspondence, and record unresolved verification limitations before marking implementation ready for archive.

## Workflow follow-up

- Review the implementation and archive only after required verification is recorded; preserve the separate cross-business stock-debt change and historical Sales by Product artifacts.
