## Why

The Reports landing page already lists "Penjualan per produk", but it is still a placeholder. The sample files under `report-sample/penjualan-per-produk` define the target Mekari-style aggregate report, and users need a real product sales summary that shows sold quantity, received return quantity, sales value, return value, and average sales price for the selected period.

## What Changes

- Add a "Penjualan per produk" report reachable from the Reports > Penjualan tab and gated by `saleReports.access`.
- Report product-level sales invoice aggregates for a selected date range, using `sales.date` for sold quantity/value.
- Report product-level received return aggregates for the selected date range, using `sale_returns.date` and only received return statuses: `Awaiting Settlement` and `Completed`.
- Support the first-scope filters: date range, period presets, customer, tag, product category, product, tag/category match logic, and sorting by product name, product code, sold quantity, return quantity, sales value, and average sales value.
- Support Excel and CSV exports that match the applied filter snapshot and include the report metadata rows shown by the sample XLSX.
- Calculate value columns as tax-exclusive line commercial values so tax-included sales align with the sample's `(dalam IDR)` sales-value semantics.
- Keep PDF export, sales quotation/order transaction-type expansion, and the "Lihat versi lebih detail" transaction-number/discount mode out of scope for this change.

## Capabilities

### New Capabilities
- `sale-by-product-report`: Provides the sales-by-product aggregate report, including filters, sold/returned quantities, tax-exclusive values, average sales price, totals, and XLSX/CSV export.

### Modified Capabilities
- `reports-landing-navigation`: Changes the existing "Penjualan per produk" sales report card from a placeholder into an actionable report link.

## Impact

- Affects `Modules/Reports` routes, controllers, landing card configuration, and report views.
- Adds a Livewire report component and report services/exports under the existing `app/Livewire/Reports`, `app/Services/Reports`, and `app/Exports` patterns.
- Reads existing Sales and Sales Return data from `sales`, `sale_details`, `sale_returns`, `sale_return_details`, `customers`, `products`, categories, tags, and units.
- Adds focused feature tests for route authorization, landing navigation, filtering, sorting, sold/return aggregation, tax-exclusive value handling, snapshot-gated exports, and export parity.
- No database migration, new permission, PDF implementation, quotation/order reporting, or transaction lifecycle change is expected.
