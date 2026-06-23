## Why

Finance and warehouse staff need a per-product inventory **valuation ledger** that shows, over a date range, how each product's on-hand stock and weighted-average cost (WAC) moved transaction by transaction, ending in a current stock value. The existing Inventory Summary report only gives a single as-of snapshot per product, so there is no way to audit *how* a product reached its current value. This report mirrors the "Nilai persediaan barang" report the client already relies on in Mekari Jurnal, closing a known gap in the reports suite.

## What Changes

- Add a new **Nilai Persediaan Barang** (Inventory Valuation) report under Reports, accessible from the reports landing page.
- Report renders a **per-transaction ledger grouped by product**: an opening-balance ("Saldo Awal") row computed from all activity strictly before the start date, followed by one row per inventory transaction within the date range, each showing the running stock, running WAC, and running value.
- Each product group ends with a **subtotal** ("Total stok di gudang" + "Subtotal nilai"); the report ends with a **grand total** ("Total nilai") summed across all products and pages.
- Filters: **date range** (Tanggal awal / Tanggal akhir) with **period presets** (Hari ini, Pekan ini, Bulan ini, Kuartal ini, Tahun ini, Kemarin, Pekan lalu, Bulan lalu, Kuartal lalu, Tahun lalu, Custom); **product category** multi-select with "Mencakup semua / Salah satu" (all/any) match modes; and **product** multi-select.
- Valuation is **company-wide** per product (single running WAC across all warehouses), consistent with the existing Inventory Summary engine.
- **CSV and XLSX export** of the full result set (all pages), including per-product subtotal rows and the grand total, matching the column order of the reference report.
- Reuse the existing inventory replay engine (transaction sorting, delta resolution, WAC recurrence, purchase/sale price and date resolution) rather than duplicating valuation logic.

## Capabilities

### New Capabilities
- `inventory-valuation-report`: A date-ranged, per-product, per-transaction inventory valuation ledger with opening balances, running stock/WAC/value, per-product subtotals, a grand total, category/product/period filters, and CSV/XLSX export.

### Modified Capabilities
- `reports-landing-navigation`: Add a navigation card/link for the new Inventory Valuation report on the reports landing page.

## Impact

- **New code**: `App\Services\Reports\InventoryValuationReportQueryService`, `InventoryValuationReportFilterData`, `App\Livewire\Reports\InventoryValuationReport`, `App\Exports\InventoryValuationReportExport`, a `Modules\Reports` controller + route + blade view, and a landing-page card.
- **Reuses**: the inventory transaction-replay logic currently in `InventorySummaryReportQueryService` (running stock, `resolveDelta`, `resolveUnitPrice`, WAC recurrence, purchase/sale price + date maps). Shared logic may be extracted to keep both reports consistent.
- **Data sources**: `Modules\Product\Entities\{Product, Transaction, ProductPrice}`, `Modules\Purchase`, `Modules\Sale`, `Modules\Adjustment\Entities\Transfer` — read-only; no schema changes.
- **Routes/Menu**: new `reports.inventory-valuation-report.index` route and a reports landing card.
- **No breaking changes**; report is additive and read-only.
