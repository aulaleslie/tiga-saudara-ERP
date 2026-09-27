## 1. Service Layer

- [x] 1.1 Create `app/Services/Reports/SaleReportFilterData.php` mirroring `PurchaseReportFilterData` (customer ids, tag ids, document/payment statuses, dates, date basis, report mode, scopeSettingId, isGlobal)
- [x] 1.2 Create `app/Services/Reports/SaleReportValidator.php` mirroring `PurchaseReportValidator`, validating the sales filter array
- [x] 1.3 Create `app/Services/Reports/SaleReportQueryService.php` with `build()` and `applySort()` — query `Sale`/`sale_details`, customers via `customer_name`, dispatched status family; support detail and header modes; honor `isGlobal` (skip `setting_id` scope when global)
- [x] 1.4 Create `app/Services/Reports/SaleReportSnapshot.php` and `SaleReportSnapshotService.php` mirroring the purchase snapshot pair (`createSnapshot`, `isValidForExport`)

## 2. Livewire Component

- [x] 2.1 Rewrite `app/Livewire/Reports/SaleReport.php` mirroring `PurchaseReport`: detail/header mode (persisted, normalized), `mount($isGlobal)` signature, period presets, date basis
- [x] 2.2 Implement searchable multi-select customer filter (`updatedCustomerSearch`, `selectCustomer`, `removeCustomer`, labels/pills) using `customer_name`
- [x] 2.3 Implement searchable multi-select tag filter (`updatedTagSearch`, `selectTag`, `removeTag`)
- [x] 2.4 Implement multi document-status and payment-status toggles using `Sale::STATUS_DISPATCHED*` and UNPAID/PARTIAL/PAID
- [x] 2.5 Implement `sortBy`/`sortIcon` with mode-aware supported sort fields (replace `supplier_purchase_number` with `reference`, `supplier_name` with `customer_name`)
- [x] 2.6 Implement `applyFilters`, `cancelFilters`, `resetFilters` against the new services and `appliedFilters` snapshot
- [x] 2.7 Implement `exportExcel`/`exportCsv` with snapshot validation; drop the unsupported PDF path or stub it like purchases

## 3. View & Export

- [x] 3.1 Rewrite `resources/views/livewire/reports/sale-report.blade.php` mirroring the purchase report view (filter panel with pills, detail/header tables, sortable headers, export buttons, global banner)
- [x] 3.2 Upgrade `app/Exports/SaleReportExport.php` to accept a built query + `SaleReportFilterData` + isCsv flag, mirroring `PurchaseReportExport` columns (customer/reference instead of supplier-specific fields)
- [x] 3.3 Confirm `SaleReportController@index`/`@indexGlobal` pass `isGlobal` and the view mounts the rebuilt component (adjust if it passed `$customers`)

## 4. Tests

- [x] 4.1 Create `Modules/Reports/Tests/Feature/SaleReportHardeningTest.php` mirroring the purchase hardening test (filters, modes, status families, global scope)
- [x] 4.2 Create `Modules/Reports/Tests/Feature/SaleReportExportParityTest.php` asserting snapshot validation and export column parity
- [x] 4.3 Create `Modules/Reports/Tests/Feature/SaleReportPerformanceTest.php` mirroring the purchase performance test
- [x] 4.4 Assert dispatched-family statuses are filterable and received-only purchase statuses are absent

## 5. Verification

- [x] 5.1 Run `php artisan test --filter SaleReport`
- [x] 5.2 Manually exercise `reports.sale-report.index` and `.global`: filter, sort, switch modes, export Excel/CSV, confirm drift-rejection on changed filters
