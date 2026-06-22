## 1. Report Entry Point

- [x] 1.1 Add a `PurchaseDeliveryReportController` and `reports.purchase-delivery.index` route under the Reports module, gated by `purchaseReports.access`.
- [x] 1.2 Add a `reports::purchase-delivery.index` Blade page with the `Pengiriman Pembelian` title, breadcrumb, and Livewire mount point.
- [x] 1.3 Update the Reports landing `Pembelian` card configuration so `Pengiriman pembelian` links to the new route instead of rendering as a placeholder.

## 2. Query and Filter Services

- [x] 2.1 Create `PurchaseDeliveryReportFilterData` with date range, period preset, supplier IDs, tag IDs, tag logic, category IDs, category logic, sort field, sort direction, and setting scope.
- [x] 2.2 Create `PurchaseDeliveryReportValidator` with Bahasa Indonesia validation messages and valid sort/tag/category logic values.
- [x] 2.3 Create `PurchaseDeliveryReportQueryService` that joins approved `received_notes` and `received_note_details` to purchases, purchase details, suppliers, products, units, and categories.
- [x] 2.4 Implement receiving-date filtering on `received_notes.date` and active-setting scoping through the related purchase.
- [x] 2.5 Implement supplier, purchase tag, and product category filters with `Salah satu` and `Mencakup semua` logic where applicable.
- [x] 2.6 Implement supplier/product/unit aggregation, amount proration by received quantity, safe zero-quantity handling, and currency rounding.
- [x] 2.7 Implement sorting by supplier, purchase delivery date, and product while keeping grouped output stable.
- [x] 2.8 Add a snapshot service for purchase delivery export guarding, following the existing report snapshot pattern.

## 3. Livewire Report UI

- [x] 3.1 Create `App\Livewire\Reports\PurchaseDeliveryReport` with default current-month dates, pending/applied filters, pagination, filter application, reset, cancel, and export actions.
- [x] 3.2 Add searchable supplier, tag, and category filter controls without preloading full datasets.
- [x] 3.3 Add period preset handling for today, week, month, quarter, year, previous-period options, and custom date ranges as supported by existing report conventions.
- [x] 3.4 Render the report table with `Supplier & Kode produk / SKU`, `Nama produk`, `Unit`, `Qty`, and `Jumlah` columns.
- [x] 3.5 Render supplier headers, supplier subtotals, grand total, empty state, and continuation behavior when pagination splits supplier groups.
- [x] 3.6 Ensure all report labels, buttons, empty states, and validation output use Bahasa Indonesia wording.

## 4. Exports

- [x] 4.1 Create `PurchaseDeliveryReportExport` for Excel and CSV using the same query/filter data as the on-screen report.
- [x] 4.2 Ensure exports include every matching row, supplier subtotals, and grand total, not only the current paginated page.
- [x] 4.3 Normalize exported numeric values to avoid floating precision artifacts in amounts and totals.
- [x] 4.4 Block export before filters are applied or after pending filters differ from the last applied snapshot.

## 5. Verification

- [x] 5.1 Add route and permission tests for authorized and unauthorized access to the purchase delivery report.
- [x] 5.2 Add Reports landing tests proving the `Pengiriman pembelian` card is actionable and no longer placeholder-treated for `purchaseReports.access` users.
- [x] 5.3 Add query service tests for approved-only receiving inclusion, pending/rejected exclusion, receiving-date filtering, active-setting scoping, and purchase-date non-control.
- [x] 5.4 Add calculation tests for partial receiving proration, multiple receiving notes for one purchase detail, missing product code display, and zero-quantity guards.
- [x] 5.5 Add filter and sorting tests for supplier, tag logic, category logic, supplier sort, delivery-date sort, and product sort.
- [x] 5.6 Add Livewire tests for apply/reset/cancel filter behavior, empty state, grouped subtotals, grand total, and pagination continuation behavior.
- [x] 5.7 Add Excel and CSV export tests for snapshot guarding, full dataset export, subtotal/grand-total parity, and amount rounding.
- [x] 5.8 Run focused report tests with `php artisan test --filter=PurchaseDeliveryReport` and related landing/report export tests.
