## Why

The Reports landing page already advertises `Pembelian per produk`, but it is still a placeholder while the sample files under `report-sample/pembelian-per-produk` define the expected Mekari/Jurnal-style report. Users need an in-system purchase product summary that shows purchased quantity, returned quantity, purchase value, return value, and average purchase value for a selected period.

## What Changes

- Add a `Pembelian per produk` report under `Laporan -> Pembelian`, gated by `purchaseReports.access`.
- Source purchase aggregates from existing purchase invoice detail rows, scoped to the active setting and filtered by purchase date.
- Source return aggregates from existing purchase return detail rows, scoped to the active setting and filtered by purchase return date, using lifecycle-valid returned records only.
- Present one row per product/unit aggregate with sample-aligned columns: `Kode produk / SKU`, `Nama produk`, `Qty pembelian`, `Qty retur`, `Unit`, `Nilai pembelian`, `Nilai retur`, and `Nilai pembelian rata-rata`.
- Support first-scope filters from the sample: date range, period presets, supplier, tag, product category, product, tag/category match logic, and sorting by product name, product code, purchase quantity, return quantity, purchase value, and average purchase value.
- Support Excel and CSV exports that match the applied filter snapshot. Excel includes the sample metadata rows: company name, `Pembelian dengan Produk`, selected date range, and `(dalam IDR)`.
- Calculate value columns as tax-exclusive line commercial values so tax-included purchase rows align with existing purchase report semantics.
- Keep PDF export, purchase order/quotation transaction-type expansion, and the `Lihat versi lebih detail` transaction-number/discount mode out of first scope.

## Capabilities

### New Capabilities
- `purchase-by-product-report`: Provides the purchase-by-product aggregate report, including filters, purchase/return quantities, tax-exclusive values, average purchase value, totals, and XLSX/CSV export.

### Modified Capabilities
- `reports-landing-navigation`: Changes the existing `Pembelian per produk` purchase report card from a placeholder into an actionable report link.

## Impact

- Affected code areas: `Modules/Reports` routes, controllers, landing card configuration, report views, `app/Livewire/Reports`, `app/Services/Reports`, `app/Exports`, and report feature tests.
- Data sources: `purchases`, `purchase_details`, `purchase_returns`, `purchase_return_details`, `suppliers`, `products`, `units`, `categories`, and purchase tags.
- Permissions: reuse `purchaseReports.access`; no new permission is expected.
- Exports: add Excel/CSV export behavior using the existing snapshot-gated report export pattern.
- Database schema: no new migrations expected; the report is read-only against existing purchase and purchase return records.
