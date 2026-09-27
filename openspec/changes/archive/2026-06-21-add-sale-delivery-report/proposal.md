## Why

The Reports landing page already lists "Pengiriman penjualan", but it is still a placeholder. Users need a real sales delivery report that matches the provided Mekari-style sample and summarizes delivered products by customer without changing the existing dispatch schema.

## What Changes

- Add a "Pengiriman penjualan" report reachable from the Reports > Penjualan tab and gated by `saleReports.access`.
- Report approved sales dispatch quantities for a selected dispatch-date range, grouped by customer and product.
- Support the sample's filters: date range, period presets, customer, tag, product category, tag/category match logic, and sorting by customer, delivery, or product.
- Support Excel and CSV exports that match the applied filter snapshot and include customer groups, subtotals, and grand total.
- Calculate quantities from existing approved `dispatches` and `dispatch_details` rows.
- Calculate monetary amounts by joining delivery quantities to existing sale and bundle commercial aggregates using the current dispatch composite key.
- Do not add a migration or depend on `dispatch_details.sale_detail_id`.

## Capabilities

### New Capabilities
- `sale-delivery-report`: Provides the sales delivery report, including filters, grouping, totals, exports, and composite-key delivery amount calculation.

### Modified Capabilities
- `reports-landing-navigation`: Changes the existing "Pengiriman penjualan" sales report card from a placeholder into an actionable report link.

## Impact

- Affects `Modules/Reports` routes, controllers, landing card configuration, and report views.
- Adds a Livewire report component and report services/exports under the existing `app/Livewire/Reports`, `app/Services/Reports`, and `app/Exports` patterns.
- Reads existing Sales data from `sales`, `sale_details`, `sale_bundle_items`, `dispatches`, `dispatch_details`, `customers`, `products`, categories, tags, and units.
- Adds focused feature tests for route authorization, report filtering/grouping/totals, composite dispatch keys, and export parity.
- No database schema changes are required.
