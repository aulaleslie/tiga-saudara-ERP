## Why

The Reports > Produk page already shows `Kuantitas stok gudang` as a placeholder, and the sample files define the exact warehouse quantity report users expect. Users need an actionable product-by-warehouse stock quantity view and exports so they can inspect per-warehouse stock without using import files or valuation reports as substitutes.

## What Changes

- Add a `Kuantitas stok gudang` report under Reports > Produk, gated by `stockMutationReports.access`.
- Replace the existing placeholder report card with a navigable report card.
- Add a report page with sample-aligned as-of date, period preset, warehouse filter, pagination, sorting where supported, and export actions.
- Compute product stock quantity rows for selected warehouses as of the selected date, with one dynamic quantity column per selected warehouse and a `Total stok` column.
- Show nullable product codes as `-` in the UI and blank in exports, matching the captured sample behavior.
- Generate CSV and XLSX exports that preserve the sample table shape: CSV starts at the table header; XLSX adds company name, report title, and selected date metadata before the same table.
- Preserve neighboring report boundaries: no stock valuation/cost columns and no transaction-detail drilldown in this change.

## Capabilities

### New Capabilities

- `warehouse-stock-quantity-report`: Provides the warehouse stock quantity report contract, including access, filters, as-of warehouse quantities, presentation, pagination, and CSV/XLSX exports.

### Modified Capabilities

- `reports-landing-navigation`: Updates the Produk tab so the `Kuantitas stok gudang` card links to the new report route instead of rendering as unavailable.

## Impact

- Affected Reports module files: routes, controller/view, Reports landing card configuration, and report landing tests.
- New report implementation files under `app/Livewire/Reports`, `app/Services/Reports`, `app/Exports`, and `resources/views/livewire/reports`.
- Reuses existing product, product stock, stock transaction, location, setting, permission, Livewire, pagination, and Maatwebsite Excel infrastructure.
- No database schema changes are expected unless implementation profiling discovers a required report index.
