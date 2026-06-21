## 1. Contract and Test Setup

- [x] 1.1 Add feature test scaffolding for `SaleDeliveryReportTest` using the existing Reports module test patterns.
- [x] 1.2 Add factories/helpers in the test file for settings, users, customers, products, sale details, bundle items, dispatches, and dispatch details.
- [x] 1.3 Add route authorization tests for users with and without `saleReports.access`.
- [x] 1.4 Add a regression assertion that this change introduces no new migration and does not require `dispatch_details.sale_detail_id`.

## 2. Report Entry Point

- [x] 2.1 Add `SaleDeliveryReportController` under `Modules/Reports/Http/Controllers`.
- [x] 2.2 Add the `reports.sale-delivery.index` route under the Reports route group with `can:saleReports.access`.
- [x] 2.3 Add `Modules/Reports/Resources/views/sale-delivery/index.blade.php` and mount the Livewire report component.
- [x] 2.4 Replace the Reports landing "Pengiriman penjualan" placeholder with an actionable route-backed card.

## 3. Filter and Snapshot Infrastructure

- [x] 3.1 Add `SaleDeliveryReportFilterData` with start/end dates, setting scope, customer IDs, tag IDs, tag logic, category IDs, category logic, sort field, and sort direction.
- [x] 3.2 Add `SaleDeliveryReportValidator` for date ranges, filter arrays, allowed match logic values, and allowed sort fields.
- [x] 3.3 Add `SaleDeliveryReportSnapshot` and `SaleDeliveryReportSnapshotService` for snapshot-gated export behavior.
- [x] 3.4 Implement customer, tag, and category searchable filter state in `SaleDeliveryReport` following the existing report components.

## 4. Query Service

- [x] 4.1 Add `SaleDeliveryReportQueryService` with a delivery aggregate sourced from approved `dispatches` and `dispatch_details`, filtered by `dispatches.dispatch_date`.
- [x] 4.2 Build the standard sale detail commercial aggregate grouped by `sale_id`, `product_id`, normalized `tax_id`, and `bundle_id = 0`.
- [x] 4.3 Build the bundle item commercial aggregate grouped by `sale_id`, `product_id`, inherited/standalone `tax_id`, and normalized `bundle_id`.
- [x] 4.4 Join delivery and commercial aggregates by the composite key and calculate delivered amount from delivered quantity and aggregate unit amount.
- [x] 4.5 Apply setting, customer, tag, category, and match-logic filters without relying on `sale_detail_id`.
- [x] 4.6 Implement sort modes for customer, delivery date, and product while keeping customer groups stable.
- [x] 4.7 Add query tests for approved-only dispatch inclusion, dispatch-date filtering, same-product different-tax separation, and standalone-vs-bundle separation.
- [x] 4.8 Add query tests for import-style dispatch rows without `sale_detail_id`.

## 5. Livewire Presentation

- [x] 5.1 Add `app/Livewire/Reports/SaleDeliveryReport.php` with explicit filter application and pagination.
- [x] 5.2 Add `resources/views/livewire/reports/sale-delivery-report.blade.php` with date filters, filter drawer, export controls, and empty state.
- [x] 5.3 Render grouped customer rows with SKU/product code, product name, unit, delivered quantity, amount, customer subtotal, and grand total.
- [x] 5.4 Add Livewire tests for grouped customer display, subtotals, grand total, empty state, and filter reset/cancel behavior.

## 6. Export

- [x] 6.1 Add `SaleDeliveryReportExport` implementing `FromQuery`, `WithHeadings`, `WithMapping`, and `WithColumnFormatting`.
- [x] 6.2 Render columns: `Tanggal` (delivery date), `No. Pengiriman` (dispatch reference), `Kode / SKU` (product code), `Nama Produk` (product name), `Qty Dikirim` (delivered quantity), `Satuan` (unit name), `Harga per unit` (unit amount), `Total Nominal` (delivered amount).
- [x] 6.3 Add `exportExcel` and `exportCsv` to `SaleDeliveryReport` protected by snapshot verification.
- [x] 6.4 Add tests verifying export behavior and snapshot protection logic.

## 7. Verification

- [x] 7.1 Run the focused Reports test suite for the sales delivery report and landing navigation.
