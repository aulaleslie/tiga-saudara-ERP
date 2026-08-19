## Why

Three usability defects were found by browser testing the "Filter lainnya" drawer on `/reports/sale-by-product`:

1. **The Filter button becomes unreachable.** Selecting many products grows the selected-value pill list without bound. The drawer is a flex column whose footer has no `flex-shrink: 0` and whose body has no `min-height: 0`, so flexbox shrinks the footer away instead of scrolling the body. The Filter and Reset buttons are pushed off-screen and cannot be clicked. All 21 report filter drawers share the identical markup and none sets `flex-shrink`, so this is a shared defect rather than a one-off.

2. **Matching products cannot all be selected.** The product lookup is hard-capped at `limit(10)` with no pagination and no bulk action. Searching "alfa ink" matches 62 products, of which 52 are unreachable through any UI path.

3. **Search is more literal than the product list.** The report passes the whole phrase to a single `LIKE %phrase%` against `product_name` only. The product list DataTable runs Yajra smart search (`config/datatables.php` sets `search.smart` and `multi_term` to true), which splits the keyword on whitespace and AND-s one `LIKE %token%` per token across every searchable column. Verified against local data: `"alf in"` returns 0 results in the report and 66 with token AND-ing. The report also omits `product_code` from both the search and the option label, which matters because product codes are opaque SKUs (`SKU-B9E1EB27 | ALFA INK TABUNG INFUS CLASSIC`) and 62 near-identical "ALFA INK …" names are otherwise indistinguishable.

None of these are regressions from `add-sale-by-product-multi-business-scope`; that change made defect 1 easier to reach but did not introduce it.

## What Changes

- Fix the Sale by Product filter drawer layout so the footer action bar is always visible and the body scrolls: `flex-shrink: 0` on header and footer, `min-height: 0` on the scrolling body.
- Cap the selected-pill area with its own scroll region, and collapse the pill list to a summary count past a threshold so a large selection cannot dominate the drawer.
- Add a "select all matching results" action to the product filter that re-runs the current search without the display limit and merges every matching identifier into the selection, including results beyond the visible options. When the match count exceeds a defined ceiling, select up to the ceiling and tell the user the selection was truncated.
- Adopt the product list's smart-search behaviour in the report filter lookups: split the search input on whitespace and require every token to match, with each token permitted to match any searched field.
- Extend the product lookup to search `product_code` in addition to `product_name`, and show the product code alongside the product name in option labels and selected pills.
- **BREAKING** (spec-level): the product filter search contract changes from single-phrase substring matching on `product_name` to multi-token matching across `product_name` and `product_code`.

Explicitly not included: typo-tolerant/fuzzy matching. `app/Services/TypoTolerantSearch.php` exists with live MySQL ngram FULLTEXT indexes and currently has no callers, but the product list does not use it either — the list uses tokenized LIKE. Wiring up FULLTEXT is separate work and is not part of matching the product list's behaviour.

## Capabilities

### New Capabilities
- `report-filter-drawer-usability`: Layout and interaction contract for the report "Filter lainnya" drawer — persistent footer actions, scrollable body, bounded selected-value display, and bulk selection of all matching search results.

### Modified Capabilities
- `sale-by-product-report`: The product, category, and customer filter option lookups use multi-token matching; the product lookup additionally searches `product_code` and labels options with the product code.

## Impact

This change is deliberately scoped to the Sale by Product report only.

Affected code:
- `resources/views/livewire/reports/sale-by-product-report.blade.php` — drawer flex fixes, bounded pill area, bulk-select control, code-bearing option labels
- `app/Livewire/Reports/SaleByProductReport.php` — tokenized search in `updatedProductSearch`, `updatedCategorySearch`, `updatedCustomerSearch`; new bulk-select action; option payload gains `product_code`

Known but deliberately out of scope, to be raised as follow-up work:
- The same drawer footer defect exists in the other 20 report filter drawer views under `resources/views/livewire/reports/`; none sets `flex-shrink`. They remain unfixed by this change.
- The same single-phrase LIKE lookups exist in `PurchaseByProductReport`, `InventoryValuationReport`, `InventoryDetailReport`, and `InventorySummaryReport`. They keep their current search behaviour.

Tests: `Modules/Reports/Tests/Feature/SaleByProductReportTest.php`.

No database migrations. No change to report aggregation, scoping, or export logic.
