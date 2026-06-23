## 1. Report Foundation

- [x] 1.1 Add route, controller, module view, and Livewire registration for the new warehouse stock valuation report page.
- [x] 1.2 Update the Reports > Produk `Nilai stok gudang` card to link to the new route and keep it gated by `inventoryValuationReports.access`.
- [x] 1.3 Add a filter data object covering as-of date, period preset, warehouse IDs, product stock status, category IDs, category match mode, warehouse name order, sort, page, and per-page values.
- [x] 1.4 Add permission/access tests for authorized access, unauthorized denial, active-setting scoping, and actionable landing-card navigation.

## 2. Query Service

- [x] 2.1 Implement a warehouse stock valuation query service that resolves active-setting warehouses, stock-managed products, categories, average-cost sources, and as-of transaction quantities.
- [x] 2.2 Reuse the existing warehouse quantity report's transaction date resolution rules for purchases, sales/dispatches, transfers, adjustments, and future movement exclusion.
- [x] 2.3 Calculate row `qty`, `minimum_qty`, `unit`, `average_cost`, `stock_value`, warehouse groups, and grand total across all matching rows.
- [x] 2.4 Apply warehouse selection, warehouse name ordering, category filtering, category match mode, product status filtering, sorting, and pagination after the report data is resolved.
- [x] 2.5 Add service tests for as-of cutoff, multiple warehouses, zero quantity, negative quantity, non-stock-managed exclusion, average-cost fallback, stock value calculation, status filters, category filters, and active-setting isolation.

## 3. Livewire UI

- [x] 3.1 Build the Livewire component state and actions for date/period/warehouse filters, advanced filter drawer values, apply/cancel/reset behavior, pagination, and export guards.
- [x] 3.2 Build the Blade view with title `Nilai stok gudang (dalam IDR)`, sample-aligned filter controls, warehouse-grouped table columns, grand total row, pagination range, loading/empty states, and average-cost note.
- [x] 3.3 Ensure nullable product codes remain reportable and display as blank or `-` in the UI.
- [x] 3.4 Add Livewire feature tests for initial state, applying filters, table columns, warehouse groups, total value display, average-cost note, empty state, pagination, and reset/cancel behavior.

## 4. Exports

- [x] 4.1 Add a CSV export that uses headers `Gudang`, `Kode Produk`, `Nama Produk`, `Qty`, `Min. Qty`, `Satuan Produk`, `Harga Rata-rata`, and `Nilai Persediaan`.
- [x] 4.2 Ensure CSV export emits flat product rows only, leaves missing product codes blank, omits metadata/group-only/total rows, and respects the applied filter snapshot.
- [x] 4.3 Add an XLSX export with title/date metadata, sample-aligned table header, warehouse group rows, product rows, formatted total row, and computed total value without formulas.
- [x] 4.4 Add export tests for CSV headers/rows/filter parity and XLSX metadata/header/grouping/total/filter parity.

## 5. Regression Boundaries

- [x] 5.1 Verify the existing inventory valuation report route, ledger table, export shape, and tests remain unchanged.
- [x] 5.2 Verify the existing warehouse stock quantity report remains quantity-only and still passes its focused tests.
- [x] 5.3 Verify rendering and exporting the new report does not create products, update product stock, update product prices, or create stock transactions.
- [x] 5.4 Run focused report tests, then run `php artisan test` or `composer test:fresh-sqlite` when the focused suite is green.
