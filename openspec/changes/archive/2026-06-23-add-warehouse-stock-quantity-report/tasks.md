## 1. Test Scaffolding

- [x] 1.1 Add feature tests for warehouse stock quantity report route permission access and denial.
- [x] 1.2 Add service tests for as-of product/location quantity calculation, including current stock, historical cutoff, future movement exclusion, zero quantity, and negative quantity.
- [x] 1.3 Add service tests for multiple selected warehouses and `Total stok` summing only selected warehouse quantities.
- [x] 1.4 Add Livewire tests for default filters, period preset behavior, warehouse selection, empty warehouse state, pagination, and nullable product code UI display as `-`.
- [x] 1.5 Add export tests for CSV headings, blank product code export cells, selected warehouse columns, and applied-filter parity.
- [x] 1.6 Add export tests for XLSX metadata rows, table headings, selected warehouse columns, selected date, and applied-filter parity.
- [x] 1.7 Update Reports landing tests to assert `Kuantitas stok gudang` is actionable for `stockMutationReports.access` users and no longer shows placeholder treatment.

## 2. Report Query Service

- [x] 2.1 Create `WarehouseStockQuantityReportFilterData` DTO to encapsulate parameters (`asOfDate`, `warehouseIds`, `periodPreset`, sorting, pagination).
- [x] 2.2 Create `WarehouseStockQuantityReportQueryService` with a `build` method returning a paginator and total aggregates.
- [x] 2.3 Apply strict `setting_id` scoping to all product and transaction queries.
- [x] 2.4 Implement as-of location quantity calculation from location-aware stock transactions, using previous/after location quantities where available and signed deltas as fallback.
- [x] 2.5 Implement row mapping with one quantity value per selected warehouse, `Total stok`, and product unit.
- [x] 2.6 Implement pagination and deterministic sorting suitable for UI and export reuse.

## 3. Report Page

- [x] 3.1 Add a Reports module controller action, route, and view for `Kuantitas stok gudang` behind `stockMutationReports.access`.
- [x] 3.2 Add the Livewire component with default as-of date, period presets, warehouse filter state, apply/reset behavior, pagination, and export actions.
- [x] 3.3 Add the Blade table with UI headings `Kode produk / SKU`, `Nama produk`, dynamic warehouse columns, `Total stok`, and `Unit`.
- [x] 3.4 Render product name links to product detail where a product detail route is available.
- [x] 3.5 Render an empty state when no active-setting warehouses or rows are available.

## 4. Exports

- [x] 4.1 Add a CSV/XLSX export class that consumes the shared query service result instead of recalculating report rows independently.
- [x] 4.2 Implement CSV output beginning directly with `Product Code`, `Product Name`, selected warehouse columns, `Total Quantity`, and `Product Unit`.
- [x] 4.3 Implement XLSX output with company name, `WAREHOUSE STOCK QUANTITY`, selected date metadata, and the same table shape after metadata rows.
- [x] 4.4 Ensure exported nullable product codes are blank while UI nullable product codes remain `-`.
- [x] 4.5 Use stable filenames aligned with the report name and selected date.

## 5. Reports Landing Integration

- [x] 5.1 Update the Reports > Produk card configuration so `Kuantitas stok gudang` links to the new report route.
- [x] 5.2 Preserve the `stockMutationReports.access` permission gate for the card.
- [x] 5.3 Ensure other Produk report cards and placeholder states remain unchanged.

## 6. Verification

- [x] 6.1 Run focused tests for the new warehouse stock quantity report, exports, and reports landing behavior.
- [x] 6.2 Run a broader report-module or focused `php artisan test` pass when the implementation touches shared report services.
- [x] 6.3 Compare generated CSV and XLSX output against `report-sample/kuantitas-stock-gudang` for headings, metadata rows, blank code behavior, warehouse columns, and row counts under equivalent seeded data.
