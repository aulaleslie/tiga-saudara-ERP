## Why

The Reports > Produk page already lists `Detail persediaan barang`, but the card is still a placeholder while the business has sample UI, CSV, and XLSX files defining the expected Mekari-style inventory detail ledger. Users need a stock-managed product movement report that shows opening stock, in-range mutations, running stock, and per-product final stock without the monetary columns from `Nilai persediaan barang`.

## What Changes

- Add a real `Detail persediaan barang` report under Reports > Produk, gated by `stockMutationReports.access`.
- Provide date-range and period filters, product category multi-select with all/any matching, product multi-select, grouped pagination, and CSV/XLSX export.
- Compute each product's `Saldo Awal` by replaying inventory activity before the selected start date, then emit each in-range movement with signed mutation and running stock.
- Match the sample report shape: quantity-only columns, product grouping, per-product `Total Stok di Tangan`, flat CSV rows, and grouped XLSX rows with title/date/currency metadata.
- Update the Reports landing Produk card from placeholder to an actionable report link.

## Capabilities

### New Capabilities

- `inventory-detail-report`: Provides the quantity-only `Detail persediaan barang` inventory movement ledger, filters, grouping, pagination, and exports.

### Modified Capabilities

- `reports-landing-navigation`: Updates the Produk tab so the `Detail persediaan barang` card links to the new report route instead of rendering as unavailable.

## Impact

- Affected code: `Modules/Reports` routes/controllers/views, `Modules/Reports/Http/Controllers/ReportsController.php`, `app/Livewire/Reports`, `app/Services/Reports`, `app/Exports`, and focused report tests.
- Reuses existing Laravel, Livewire, Bootstrap, Maatwebsite Excel, Eloquent, `Transaction`, `Product`, and inventory replay helper patterns.
- No database schema changes and no changes to historical inventory transactions.
