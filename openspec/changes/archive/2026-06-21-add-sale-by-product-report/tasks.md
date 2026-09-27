## 1. Contract and Test Setup

- [x] 1.1 Add feature test scaffolding for `SaleByProductReportTest` using the existing Reports module test patterns.
- [x] 1.2 Add factories/helpers in the test file for settings, users, customers, products, categories, tags, sales, sale details, sale returns, and sale return details.
- [x] 1.3 Add route authorization tests for users with and without `saleReports.access`.
- [x] 1.4 Add a regression assertion that this change introduces no database migration and does not change Sales or Sales Return lifecycle behavior.

## 2. Report Entry Point

- [x] 2.1 Add `SaleByProductReportController` under `Modules/Reports/Http/Controllers`.
- [x] 2.2 Add the `reports.sale-by-product.index` route under the Reports route group with `can:saleReports.access`.
- [x] 2.3 Add `Modules/Reports/Resources/views/sale-by-product/index.blade.php` and mount the Livewire report component.
- [x] 2.4 Replace the Reports landing "Penjualan per produk" placeholder with an actionable route-backed card.

## 3. Filter and Snapshot Infrastructure

- [x] 3.1 Add `SaleByProductReportFilterData` with start/end dates, setting scope, customer IDs, tag IDs, tag logic, category IDs, category logic, product IDs, sort field, sort direction, and period preset.
- [x] 3.2 Add `SaleByProductReportValidator` for date ranges, filter arrays, allowed match logic values, product IDs, and allowed sort fields.
- [x] 3.3 Add `SaleByProductReportSnapshot` and `SaleByProductReportSnapshotService` for snapshot-gated export behavior.
- [x] 3.4 Implement customer, tag, category, and product searchable filter state in `SaleByProductReport` following existing report components.

## 4. Query Service

- [x] 4.1 Add `SaleByProductReportQueryService` with a sold aggregate sourced from `sales` and `sale_details`, filtered by `sales.date` and scoped to the current setting.
- [x] 4.2 Calculate sold value as tax-exclusive line value, subtracting `sale_details.product_tax_amount` when `sales.is_tax_included` is true.
- [x] 4.3 Add a received return aggregate sourced from `sale_returns` and `sale_return_details`, filtered by `sale_returns.date`, current setting, and statuses `Awaiting Settlement` or `Completed` case-insensitively.
- [x] 4.4 Merge sold and return aggregates by product identity and unit, preferring `product_id` while retaining persisted product code/name fallbacks.
- [x] 4.5 Calculate `Harga Penjualan Rata-rata` as sold value divided by sold quantity with zero-safe handling.
- [x] 4.6 Apply setting, customer, tag, category, product, and match-logic filters consistently to sold and return aggregates.
- [x] 4.7 Implement sort modes for product name, product code, sold quantity, return quantity, sold value, and average sales value with deterministic fallback ordering.
- [x] 4.8 Add query tests for sale-date inclusion, return-date inclusion, received-return status filtering, setting scoping, tax-exclusive value calculation, average price calculation, and blank product-code handling.

## 5. Livewire Presentation

- [x] 5.1 Add `app/Livewire/Reports/SaleByProductReport.php` with explicit filter application, pagination, reset/cancel behavior, snapshot creation, and export actions.
- [x] 5.2 Add `resources/views/livewire/reports/sale-by-product-report.blade.php` with date filters, filter drawer, export controls, sortable columns, pagination, and empty state.
- [x] 5.3 Render columns for `Kode Produk`, `Nama Produk`, `Kuantitas Terjual`, `Kuantitas Retur`, `Satuan`, `Total Nilai terjual`, `Total Nilai Retur`, and `Harga Penjualan Rata-rata`.
- [x] 5.4 Render a total row for `Total Nilai terjual` and `Total Nilai Retur` when matching rows exist.
- [x] 5.5 Add Livewire tests for aggregate display, filters, sorting, totals, empty state, reset/cancel behavior, and first-scope exclusion of PDF/detail mode controls.

## 6. Export

- [x] 6.1 Add `app/Exports/SaleByProductReportExport.php` extending `FromQuery`, `WithHeadings`, `WithMapping`, and `WithCustomCsvSettings`.
- [x] 6.2 Render headings: `Kode Produk`, `Nama Produk`, `Kuantitas Terjual`, `Kuantitas Retur`, `Satuan`, `Total Nilai terjual`, `Total Nilai Retur`, and `Harga Penjualan Rata-rata`.
- [x] 6.3 Map rows with raw numerical values for CSV and unformatted numerics for Excel to preserve native spreadsheet capabilities.
- [x] 6.4 Implement snapshot validation against `SaleByProductReportSnapshotService` within the Livewire export actions before generating the file.
- [x] 6.5 Add export validation and structure tests.havior, metadata rows, export totals, CSV/XLSX parity, and snapshot protection.

## 7. Verification

- [x] 7.1 Run the focused Reports tests for sales by product and landing navigation.
- [x] 7.2 Run related sales report export tests to verify existing reports are not regressed.
- [x] 7.3 Run `php artisan test --filter=SaleByProductReportTest` and any focused landing-navigation test filter added by this change.
