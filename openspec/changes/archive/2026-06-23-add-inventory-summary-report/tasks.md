## 1. Report Skeleton

- [x] 1.1 Add `InventorySummaryReportController`, report route, module view, and Livewire registration using existing Reports module conventions.
- [x] 1.2 Gate the report route, Livewire actions, and export actions with `inventoryValuationReports.access`.
- [x] 1.3 Update the Reports landing Produk card for `Ringkasan persediaan barang` from placeholder to linked actionable report.
- [x] 1.4 Update report navigation/menu active-state handling so the new report route marks the Reports area active.

## 2. Calculation Service

- [x] 2.1 Create an inventory summary filter object or normalized filter array covering as-of date, stock status, category ids, category match mode, product ids, sort column, and sort direction.
- [x] 2.2 Create an inventory summary row/result service under `app/Services/Reports` that loads stock-managed products for the active setting with category, unit, price, and transaction context.
- [x] 2.3 Implement as-of stock reconstruction from transactions up to the selected date, aggregated across all locations.
- [x] 2.4 Implement average-cost and value calculation, reusing or extracting proven logic from the existing inventory valuation export where safe.
- [x] 2.5 Preserve nullable product codes as blank display/export values.
- [x] 2.6 Implement stock status filters for available, out-of-stock, and below-minimum behavior, including negative stock handling.
- [x] 2.7 Implement category, category match mode, product, sort, total count, and total value behavior in the shared service.

## 3. Livewire UI

- [x] 3.1 Create the `App\Livewire\Reports\InventorySummaryReport` component with default date/period, applied filter state, pagination state, and reset/apply behavior.
- [x] 3.2 Build the Blade UI with title, currency note, date/period controls, filter controls, sortable table, total product count, total value, and pagination.
- [x] 3.3 Ensure the filter UI does not include a warehouse selector.
- [x] 3.4 Handle the deferred inventory-account toggle without adding guessed account columns or values.
- [x] 3.5 Add loading, empty-state, validation, and permission-safe export button behavior consistent with existing report pages.

## 4. Exports

- [x] 4.1 Create an `InventorySummaryReportExport` that consumes the shared calculation service and active filters.
- [x] 4.2 Implement CSV export with headers as the first row and no metadata rows.
- [x] 4.3 Implement XLSX export with `Inventory Summary` sheet name, company/date/currency/sort metadata rows, table headers, rows, and total value.
- [x] 4.4 Add export filenames following the sample date-based naming convention.
- [x] 4.5 Ensure UI, CSV, and XLSX use the same filtered and sorted dataset and total value.

## 5. Tests

- [x] 5.1 Add route/controller permission tests for authorized and unauthorized report access.
- [x] 5.2 Add Reports landing tests confirming the Produk card is actionable for `inventoryValuationReports.access` users and not shown to unauthorized users.
- [x] 5.3 Add service tests for as-of stock reconstruction across purchases, sales/dispatches, adjustments, initialization-only products, and all-location aggregation.
- [x] 5.4 Add service tests for nullable product code, minimum stock mapping, negative stock, stock status filters, product/category filters, sorting, counts, and total value.
- [x] 5.5 Add Livewire tests for applying filters, resetting filters, pagination totals, absence of warehouse selector, and deferred account toggle behavior.
- [x] 5.6 Add export tests for CSV header-first shape, XLSX metadata/table/total shape, and UI/export parity.

## 6. Verification

- [x] 6.1 Run focused report and inventory summary tests.
- [x] 6.2 Run `php artisan test` with relevant report filters or `composer test:fresh-sqlite` if migrations/service behavior require broader confidence.
- [x] 6.3 Manually compare generated CSV/XLSX shape against `report-sample/ringkasan-persediaan-barang` for column order, metadata rows, totals, and blank product codes.
