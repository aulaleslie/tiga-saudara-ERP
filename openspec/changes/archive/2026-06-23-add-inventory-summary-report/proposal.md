## Why

The Reports > Produk page already exposes a `Ringkasan persediaan barang` card, but it is still a placeholder while the business has sample files defining a Mekari-style product inventory summary report. Users need a stock-managed product summary that reconciles product quantity, minimum stock, average cost, and total inventory value as of a selected date without using the heavier transaction-detail inventory reports.

## What Changes

- Add a `Ringkasan persediaan barang` report under Reports > Produk, gated by `inventoryValuationReports.access`.
- Replace the existing placeholder report card with a navigable report card.
- Add a report page with single-date/period controls, product stock status filtering, category filtering, product filtering, sorting, pagination, and export actions based on `report-sample/ringkasan-persediaan-barang`.
- Compute product-level as-of inventory rows across all locations for the active setting, including nullable product code, product name, stock on hand, minimum stock, unit, average cost, and total value.
- Generate CSV and XLSX exports that match the sample-specific table shape, metadata rows, date formatting, currency note, sorting metadata, and total value behavior.
- Preserve neighboring warehouse report boundaries: Ringkasan aggregates all locations and does not add a warehouse selector in this change.
- Defer the optional `Tampilkan akun persediaan barang` output until a reliable inventory-account data source is identified.

## Capabilities

### New Capabilities

- `inventory-summary-report`: Provides the inventory summary report contract, including access, filters, as-of stock valuation, presentation, and exports.

### Modified Capabilities

- `reports-landing-navigation`: Updates the Produk tab so the `Ringkasan persediaan barang` card links to the new report route instead of rendering as unavailable.

## Impact

- Affected Reports module files: routes, controller/view, Reports landing card configuration, and report landing tests.
- New report implementation files under `app/Livewire/Reports`, `app/Services/Reports`, `app/Exports`, and `resources/views/livewire/reports`.
- Reuses existing product, product stock, product price, transaction, category, unit, setting, permission, Livewire, and Maatwebsite Excel infrastructure.
- No database schema changes are expected.
