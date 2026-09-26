# Tasks

## 1. Access and Report Entry

- [x] 1.1 Register `stockInsights.access` in the existing permission catalog/role administration flow and verify a focused permission-registration test can assign and resolve it.
- [x] 1.2 Add the `Pantauan Stok` card under `Laporan → Produk`, include the permission in reports-landing eligibility, and verify focused landing-page tests show the card/tab only to an authorized user while preserving all existing cards.
- [x] 1.3 Add the dedicated `reports.stock-insights.index` route and page/controller boundary with route, mount, and action authorization, and verify focused feature tests return 403 and expose no data for an unauthorized request.

## 2. Projection Contracts and Current Stock

- [x] 2.1 Add report filter/projection data objects for product search, status/category/brand filters, rolling start date, sorting, pagination, stock hierarchy, financial completeness, and attention flags; verify focused unit tests cover normalization and deterministic defaults.
- [x] 2.2 Implement the current ProductStock aggregate for active non-merged stock-managed products across all businesses and active locations, preserving all four tax/condition buckets while deriving Good and informational Broken totals; verify focused query tests reconcile location → business → global and exclude inactive locations/services.
- [x] 2.3 Implement SQL-backed status projection and counts for `Stok Habis`, `Perlu Dibeli Lagi`, and `Batas Minimum Belum Diatur` from global Good stock and `product_stock_alert`; verify equality, zero/negative stock, zero minimum, and fractional-current-stock fixtures.
- [x] 2.4 Implement the fixed 90-day `Lama Tidak Terjual` projection using positive global Good stock, product age, fulfilled-sale eligibility, and current SaleDetail values; verify focused tests suppress the flag for new and out-of-stock products.
- [x] 2.5 Implement status, category, brand, and tokenized product identity filters plus default priority and explicit stable sorts without loading the full catalog into PHP; verify focused query tests cover combined filters, OR status semantics, pagination, and deterministic tie-breaking.

## 3. Sales and Financial Projection

- [x] 3.1 Implement inclusive rolling-period normalization with a seven-day default, 7/30/90 presets, custom start-derived `N Hari Terakhir`, application-timezone boundaries, fixed end today, and future-date rejection; verify focused boundary tests including today-only and preset matching.
- [x] 3.2 Aggregate current persisted SaleDetail quantities, tax-exclusive values, and last-sale dates for fulfilled-report-eligible sales across all businesses using effective reporting dates, with no Sales Return join or subtraction; verify a focused regression test proves an already-reduced sale detail is not deducted twice.
- [x] 3.3 Allocate eligible sale-header discounts proportionally and deterministically to current product-line revenue, and verify focused decimal/rounding tests reconcile every allocation to its header discount.
- [x] 3.4 Aggregate current snapshot HPP per product, attributing bundle component cost exactly once and omitting explicit return-cost reversal, and verify focused normal-line, bundle, modified-quantity, and no-double-count fixtures.
- [x] 3.5 Calculate gross profit and financial completeness flags, marking null/unusable stock-managed snapshots as incomplete, and verify focused tests cover reliable, partially incomplete, and zero-cost non-stock edge cases while the report itself continues to exclude non-stock products.
- [x] 3.6 Add reconciliation-focused fixtures proving product sales value, allocated discounts, HPP, and gross profit agree within rounding tolerance for the supported current-document semantics; run only the dedicated Stock Insights financial test file/filter.

## 4. Interactive Report UI

- [x] 4.1 Build the Livewire report page with Indonesian title, subtitle, summary/attention counts, empty states, product identity, global minimum/status, sales and financial columns, loading feedback, and pagination; verify focused component rendering tests assert Bahasa Indonesia labels and expected row values.
- [x] 4.2 Implement the stock matrix with one always-visible sortable `Stok Global` column, a separate global expansion control, non-sortable business columns, and independent business-to-location grouped expansion matching Stok Lintas Bisnis; verify focused Livewire tests cover collapse/expand reconciliation and sorting while expanded.
- [x] 4.3 Add concise global/business/location composition tooltips or popovers for Good tax, Good non-tax, Broken tax, and Broken non-tax quantities without changing primary values; verify focused rendering tests cover mixed and zero bucket compositions.
- [x] 4.4 Add product search, multi-status, category, brand, period preset, and start-date controls; synchronize presets and system-generated custom duration display, reject future dates in UI/server validation, reset pagination on relevant changes, and verify focused component tests cover each transition.
- [x] 4.5 Add clickable sortable headers only for supported global/product/sales/financial fields and verify focused component tests cover ascending/descending toggles, stable fallback ordering, and the absence of child-column sort actions.

## 5. Inline Minimum-Stock Update

- [x] 5.1 Add the small accessible button beside each product name with `Atur batas minimum stok` tooltip/label and a more prominent missing-minimum treatment; verify focused rendering tests cover configured and unconfigured products.
- [x] 5.2 Build the focused minimum modal with fresh product identity, global stock/bucket composition, recent sales context, scope explanation, and positive-integer minimum validation; verify focused component tests prove it does not expose unrelated product-edit fields.
- [x] 5.3 Implement the authorized, narrowly scoped minimum update with locked product eligibility validation and existing audit convention recording actor/old/new values; verify focused tests prove only `product_stock_alert` changes and unauthorized/ineligible/invalid requests fail atomically.
- [x] 5.4 Refresh counts and rows after save while retaining current filters and expansion state, normalize pagination only when needed, and display feedback when the row leaves the active filter; verify focused Livewire tests cover both retained-row and disappearing-row paths.

## 6. Focused Verification and Human Acceptance Handoff

- [x] 6.1 Run only the dedicated/focused Stock Insights unit and feature tests plus any directly affected Reports landing permission test, record the commands and passing results, and explicitly do not run the full automated suite.
- [x] 6.2 Review query behavior with focused query-count or query-log assertions for a representative paginated matrix and verify the implementation uses bounded grouped queries rather than per-product/business/location N+1 reads.
- [x] 6.3 Produce a concise human browser checklist covering card visibility/403, Indonesian content, global/business/location expansion, global sorting, filters and custom period display, financial warnings, minimum modal save, filter-preserving refresh, and responsive horizontal scrolling; do not install or invoke Chrome, Chromium, Playwright, Selenium, or any browser automation command.
