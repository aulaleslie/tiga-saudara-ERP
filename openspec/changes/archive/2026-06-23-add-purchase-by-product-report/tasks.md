## 1. Contract and Test Setup

- [x] 1.1 Add feature test scaffolding for `PurchaseByProductReportTest` using existing Reports module test patterns.
- [x] 1.2 Add test helpers for settings, users, suppliers, products, categories, tags, purchases, purchase details, purchase returns, purchase return details, and lifecycle-valid return states.
- [x] 1.3 Add route authorization tests for users with and without `purchaseReports.access`.
- [x] 1.4 Add regression assertions that this change introduces no database migration and does not alter purchase, receiving, stock, serial, payment, or purchase return workflows.

## 2. Report Entry Point

- [x] 2.1 Add `PurchaseByProductReportController` under `Modules/Reports/Http/Controllers`.
- [x] 2.2 Add the `reports.purchase-by-product.index` route under the Reports route group with `can:purchaseReports.access`.
- [x] 2.3 Add `Modules/Reports/Resources/views/purchase-by-product/index.blade.php` with title, breadcrumb, and Livewire mount point.
- [x] 2.4 Update the Reports landing `Pembelian per produk` card from placeholder to route-backed actionable card.
- [x] 2.5 Add Reports landing tests proving the card is visible for `purchaseReports.access`, links to the new route, and no longer has placeholder treatment.

## 3. Filter and Snapshot Infrastructure

- [x] 3.1 Add `PurchaseByProductReportFilterData` with date range, setting scope, supplier IDs, tag IDs, tag logic, category IDs, category logic, product IDs, sort field, sort direction, and period preset.
- [x] 3.2 Add `PurchaseByProductReportValidator` for date ranges, filter arrays, allowed match logic values, product IDs, supplier IDs, and allowed sort fields.
- [x] 3.3 Add `PurchaseByProductReportSnapshot` and `PurchaseByProductReportSnapshotService` for snapshot-gated export behavior.
- [x] 3.4 Implement supplier, tag, category, and product searchable filter state in `PurchaseByProductReport` following existing report components.
- [x] 3.5 Implement period presets for current and previous report periods supported by adjacent purchase reports.

## 4. Query Service

- [x] 4.1 Add `PurchaseByProductReportQueryService` with a purchase aggregate sourced from `purchases` and `purchase_details`, filtered by `purchases.date` and scoped to the active setting.
- [x] 4.2 Calculate purchase value as tax-exclusive line value, subtracting `purchase_details.product_tax_amount` when `purchases.is_tax_included` is true.
- [x] 4.3 Add a purchase return aggregate sourced from `purchase_returns` and `purchase_return_details`, filtered by `purchase_returns.date`, active setting, approved status, and lifecycle-valid return execution or settlement state.
- [x] 4.4 Exclude draft, pending, rejected, and approved-but-not-dispatched purchase returns from return quantity/value.
- [x] 4.5 Merge purchase and return aggregates by product identity and unit, preferring `product_id` while retaining persisted product code/name fallbacks.
- [x] 4.6 Calculate `Nilai pembelian rata-rata` as purchase value divided by purchase quantity with zero-safe handling.
- [x] 4.7 Apply setting, supplier, tag, category, product, and match-logic filters consistently to purchase and return aggregates.
- [x] 4.8 Implement sort modes for product name, product code, purchase quantity, return quantity, purchase value, and average purchase value with deterministic fallback ordering.
- [x] 4.9 Add query tests for purchase-date inclusion, return-date inclusion, return lifecycle filtering, setting scoping, tax-exclusive value calculation, average value calculation, blank product-code handling, and zero purchase value handling.

## 5. Livewire Presentation

- [x] 5.1 Add `app/Livewire/Reports/PurchaseByProductReport.php` with explicit filter application, pagination, reset/cancel behavior, snapshot creation, and export actions.
- [x] 5.2 Add `resources/views/livewire/reports/purchase-by-product-report.blade.php` with date filters, filter drawer, export controls, sortable columns, pagination, and empty state.
- [x] 5.3 Render columns for `Kode produk / SKU`, `Nama produk`, `Qty pembelian`, `Qty retur`, `Unit`, `Nilai pembelian`, `Nilai retur`, and `Nilai pembelian rata-rata`.
- [x] 5.4 Render a total row for `Nilai pembelian` and `Nilai retur` when matching rows exist.
- [x] 5.5 Ensure first-scope UI omits or disables PDF export, transaction-type expansion, and `Lihat versi lebih detail` mode.
- [x] 5.6 Add Livewire tests for aggregate display, filters, sorting, totals, empty state, reset/cancel behavior, snapshot state, and first-scope exclusions.

## 6. Export

- [x] 6.1 Add `app/Exports/PurchaseByProductReportExport.php` using the same query/filter data as the on-screen report.
- [x] 6.2 Render headings: `Kode produk / SKU`, `Nama produk`, `Qty pembelian`, `Qty retur`, `Unit`, `Nilai pembelian`, `Nilai retur`, and `Nilai pembelian rata-rata`.
- [x] 6.3 Add XLSX metadata rows for company name, `Pembelian dengan Produk`, selected date range, and `(dalam IDR)`.
- [x] 6.4 Keep CSV output limited to headings and data rows without XLSX metadata rows.
- [x] 6.5 Export all matching rows and totals, not only the current paginated page.
- [x] 6.6 Normalize exported numeric values to avoid floating precision artifacts in values, averages, and totals.
- [x] 6.7 Implement snapshot validation in Livewire export actions before generating XLSX or CSV.
- [x] 6.8 Add export tests for metadata rows, CSV structure, full dataset export, totals parity, numeric rounding, and snapshot protection.

## 7. Verification

- [x] 7.1 Run `openspec validate add-purchase-by-product-report --strict`.
- [x] 7.2 Run focused report tests with `php artisan test --filter=PurchaseByProductReport`.
- [x] 7.3 Run focused Reports landing tests that cover `Pembelian per produk`.
- [x] 7.4 Run adjacent report tests for purchase delivery, purchase by supplier, and sale by product if the shared report/export code is touched.
- [x] 7.5 Run `composer test:fresh-sqlite` if shared report infrastructure or migrations are affected during implementation.
