## 1. Service Layer

- [x] 1.1 Create `app/Services/Reports/SaleByCustomerReportFilterData.php` mirroring `PurchaseBySupplierReportFilterData` (customerIds, tagIds, tagLogic, categoryIds, categoryLogic, dates, sort, scopeSettingId)
- [x] 1.2 Create `app/Services/Reports/SaleByCustomerReportValidator.php` mirroring the purchase validator
- [x] 1.3 Create `app/Services/Reports/SaleByCustomerReportQueryService.php` with `build()` + `applySort()` — join `sale_details` to `sales`, group/sort by `customer_id`, apply tag/category AND-OR logic, scope to `setting_id`
- [x] 1.4 Create `app/Services/Reports/SaleByCustomerReportSnapshot.php` and `SaleByCustomerReportSnapshotService.php` mirroring the purchase snapshot pair

## 2. Livewire Component

- [x] 2.1 Create `app/Livewire/Reports/SaleByCustomerReport.php` mirroring `PurchaseBySupplierReport`: dates + period presets, `filterTriggered` gating, `appliedFilters`
- [x] 2.2 Implement searchable multi-select customer filter using `customer_name`
- [x] 2.3 Implement searchable multi-select tag and category filters with `tagLogic`/`categoryLogic` ("Salah satu"/"Semua"); categories scoped by `setting_id`
- [x] 2.4 Implement `applyFilters`/`cancelFilters`/`resetFilters` and `exportExcel`/`exportCsv` with snapshot validation
- [x] 2.5 Port the running per-customer subtotal logic in `render()`, including the windowed previous-page pre-query so subtotals carry across pages (accumulate by `customer_id`)

## 3. View, Controller, Route, Menu

- [x] 3.1 Create `resources/views/livewire/reports/sale-by-customer-report.blade.php` mirroring the purchase-by-supplier view (filter pills, logic selectors, grouped rows, running subtotal column)
- [x] 3.2 Create `app/Exports/SaleByCustomerReportExport.php` mirroring `PurchaseBySupplierReportExport` (customer instead of supplier columns)
- [x] 3.3 Create `Modules/Reports/Http/Controllers/SaleByCustomerReportController.php` with `index()` gated by `saleReports.access`, rendering `reports::sale-by-customer.index`
- [x] 3.4 Create `Modules/Reports/Resources/views/sale-by-customer/index.blade.php` mounting `<livewire:reports.sale-by-customer-report />`
- [x] 3.5 Add route `reports.sale-by-customer.index` in `Modules/Reports/Routes/web.php` with `middleware('can:saleReports.access')`
- [x] 3.6 Restructure the sales reports section in `resources/views/layouts/menu.blade.php` into a "Penjualan" dropdown (Daftar Penjualan / Penjualan Per Customer / Laporan Penjualan Global), mirroring the "Pembelian" dropdown and preserving active-state highlighting

## 4. Tests

- [x] 4.1 Create `Modules/Reports/Tests/Feature/SaleByCustomerReportTest.php` mirroring `PurchaseBySupplierReportTest`
- [x] 4.2 Assert grouping by customer and tag/category AND-OR logic (any vs all)
- [x] 4.3 Assert running subtotal carry-over continues across a pagination boundary
- [x] 4.4 Assert `saleReports.access` gates the route (403 without it)

## 5. Verification

- [x] 5.1 Run `php artisan test --filter SaleByCustomerReportTest`
- [x] 5.2 Verify in browser: open the new report from the restructured menu; filter, switch logic, paginate to confirm subtotals carry, export Excel/CSV
