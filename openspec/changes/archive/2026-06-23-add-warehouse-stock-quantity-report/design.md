## Context

The Reports > Produk landing tab already lists `Kuantitas stok gudang`, but the card is still a placeholder. The sample files under `report-sample/kuantitas-stock-gudang/` define a Mekari/Jurnal-style warehouse stock quantity report with an as-of date, period presets, a warehouse selector, paginated product rows, CSV export, and XLSX export.

Neighboring samples establish the report boundary. `Kuantitas stok gudang` is quantity-only and warehouse-specific. `Nilai stok gudang` is the value-oriented warehouse report, while `Ringkasan persediaan barang` aggregates all locations without a warehouse selector. Existing stock snapshot import work also uses the same `warehouse_stock_quantity.csv` shape as import input, but this change is a report/export surface, not an import flow.

The existing Laravel app already has useful building blocks:

- `Modules/Reports` routes, controllers, landing-card configuration, and feature tests.
- `app/Livewire/Reports/*` report components with filter, pagination, and export actions.
- `app/Services/Reports/*QueryService` classes that keep UI/export calculations aligned.
- `app/Exports/*` classes using Maatwebsite Excel.
- `Product`, `ProductStock`, `Location`, and `Transaction` data for current and historical location stock quantities.

## Goals / Non-Goals

**Goals:**

- Add a real `Kuantitas stok gudang` report under Reports > Produk.
- Compute product rows by selected warehouse/location as of the selected date.
- Render dynamic warehouse quantity columns, `Total stok`, and `Unit`.
- Support sample-defined controls: as-of date, period presets, warehouse selection, show/apply action, pagination, and CSV/XLSX export.
- Preserve sample export behavior: CSV begins at the table header; XLSX includes company name, report title, selected date, then the same table.
- Keep UI/export parity by using a shared query service.
- Keep the report behind `stockMutationReports.access`.

**Non-Goals:**

- No inventory value, average cost, subtotal value, or currency output.
- No transaction-detail expansion or movement history.
- No stock snapshot import behavior changes.
- No product creation or stock mutation.
- No global/cross-setting report mode.
- No schema migration unless implementation profiling proves an index is required.

## Decisions

### Decision 1: Implement as a warehouse-location report

The report should treat each selected warehouse as a `locations` row and produce one quantity column per selected location. `Total stok` is the sum of those selected-location quantities for the product.

Rationale:

- The sample UI has a `Gudang` filter and a dynamic warehouse column named `Unassigned`.
- The CSV/XLSX exports include `Unassigned` and `Total Quantity`, so selected warehouses are columns rather than row group labels.
- This keeps the report distinct from all-location inventory summary reports.

Alternative considered: create one row per product/location pair. Rejected because it would not match the sample export shape.

### Decision 2: Derive as-of stock from location-aware inventory transactions

For historical dates, the query service should derive each product/location quantity from `transactions` up to the selected end-of-day, using location-aware previous/after quantity fields where available and falling back to signed transaction quantity rules already used by stock mutation reporting. For current-day/current-state views, the service may use `product_stocks` as an optimization only when it preserves the same output.

Rationale:

- The UI label is `Per` and the sample XLSX stores `21/06/2026`, so the report is date-sensitive.
- Current `product_stocks` alone cannot answer historical dates.
- Existing reports already use transaction date resolution for purchase, sale, transfer, adjustment, and init stock events.

Alternative considered: use only current `product_stocks` and treat the date as display metadata. Rejected because it would make historical date filters misleading.

### Decision 3: Use a shared query service for UI and exports

Create a dedicated report service, for example `WarehouseStockQuantityReportQueryService`, with a filter DTO and a stable result shape containing selected locations, rows, totals, and pagination metadata. Livewire and export classes should consume this same service.

Rationale:

- UI/export parity is central for report samples.
- Dynamic warehouse columns are easy to drift if duplicated.
- Tests can assert the service once and then verify rendering/export mapping.

Alternative considered: put all calculation logic in the Livewire component. Rejected because exports would need duplicate warehouse and as-of calculations.

### Decision 4: Preserve sample display differences between UI, CSV, and XLSX

UI headings should stay Indonesian: `Kode produk / SKU`, `Nama produk`, selected warehouse names, `Total stok`, `Unit`. CSV/XLSX table headings should stay sample-aligned in English: `Product Code`, `Product Name`, selected warehouse names, `Total Quantity`, `Product Unit`. The UI should display missing product codes as `-`; exports should output blank product codes.

Rationale:

- The captured UI and export files intentionally differ in language and blank-code presentation.
- Existing report work already preserves sample-specific export shapes rather than forcing one universal label set.

Alternative considered: localize export headings to Indonesian. Rejected because it would diverge from the provided export sample.

### Decision 5: Default warehouse selection to all active-setting locations when no explicit filter is applied

The initial report should be able to show data without forcing a warehouse choice. If the user explicitly selects warehouses, only those locations are included. If no active-setting location exists, the report should render an empty state rather than falling back to another setting.

Rationale:

- The sample shows one warehouse option (`Unassigned`) selected, but production data can have multiple locations.
- Falling back across settings would violate report permission and tenant boundaries.

Alternative considered: require at least one warehouse before showing rows. Rejected because report pages in this codebase commonly have usable defaults.

## Risks / Trade-offs

- Historical transaction replay can be expensive with many products and locations -> Scope queries to active setting, selected locations, stock-managed products, and selected date; add chunking or an index only after profiling.
- Existing transactions may not always have complete location previous/after values -> Prefer location quantity fields when present, fall back to signed deltas, and cover init, adjustment, transfer, purchase, sale/dispatch cases in tests.
- Dynamic warehouse columns can make exports wide -> Preserve the sample model and rely on selected warehouse filtering for operator control.
- Negative quantities may surprise users -> Display/export them as real stock state, matching the sample file that contains one negative row.
- Product codes are nullable -> Keep the UI/export distinction explicit and tested.

## Migration Plan

1. Add the report route/controller/view behind `stockMutationReports.access`.
2. Add the Livewire component, Blade view, filter DTO, query service, and export class.
3. Update the Reports > Produk card from placeholder to actionable.
4. Add focused feature, Livewire/service, and export tests for permissions, filters, as-of stock, dynamic warehouse columns, pagination, negative/zero quantities, nullable product code display, and CSV/XLSX shape.
5. Rollback removes the new route/component/service/export and returns the card to placeholder state; no data rollback is expected.

## Open Questions

- Should archived or inactive locations be excluded by default if the schema has an active flag, or should every active-setting location be eligible until a later location-status policy exists?
- Should future report work add product/category filters to this report, or keep the first implementation to the controls captured in the sample?
