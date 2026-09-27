## Why

Imported sales and purchases carry a document-level discount (e.g. invoice `JL.2025.7943` has a `45,045.05` discount), and the import correctly stores it on `Sale.discount_amount` / `Purchase.discount_amount`. However none of the sales/purchase reports surface that discount as its own line or a correctly-labeled column. The row-expanding reports expand each line into only two rows (product/DPP + tax), so the discount silently disappears and report rows no longer reconcile to the document total. The expectation is three rows — DPP, Discount, Tax — that sum back to the invoice total.

## What Changes

- **Per Customer / Per Supplier reports** (row-expanding): emit one `Diskon` row per invoice, between the invoice's product/DPP lines and its tax line, sourced from the document discount (`Sale.discount_amount` / `Purchase.discount_amount`). A discounted invoice now expands to product/DPP row(s) → Diskon row → Pajak row, and the running total reconciles to the document total.
- **Daftar Penjualan / Daftar Pembelian** (flat, detail + header + Global): relabel the discount columns so the real document discount is clearly the discount. The always-zero per-line `Diskon`/`Diskon Per Baris %` columns (backed by `product_discount_amount`, which is never populated by import) are dropped/hidden; the document discount currently mislabeled as `Jumlah Pemotongan` is presented as the document `Diskon`, and the derived `Diskon %` is retained.
- Keep matching CSV/XLSX exports in parity with the on-screen reports for all four report families.
- No import changes and no re-import: the discount data already exists on the documents. This is a report-presentation change only.

## Capabilities

### New Capabilities
<!-- None: all four affected reports already have capability specs. -->

### Modified Capabilities
- `sales-by-customer-report`: add a per-invoice document `Diskon` row to the row expansion so discounted invoices show DPP → Diskon → Pajak and reconcile to the document total.
- `purchase-by-supplier-report`: add a per-invoice document `Diskon` row to the supplier-grouped row expansion, mirroring the sales-by-customer behavior.
- `sales-list-report`: relabel/clean the discount columns so the document discount (not the always-zero per-line column) is the displayed `Diskon`, with the derived `Diskon %` retained, in detail, header, and Global modes.
- `purchase-list-report`: same discount-column relabel/clean for `Daftar Pembelian` in detail, header, and Global modes.

## Impact

- Report query/mapping services: `app/Services/Reports/SaleByCustomerReportQueryService.php`, `PurchaseBySupplierReportQueryService.php`, `SaleReportQueryService.php`, `PurchaseReportQueryService.php`.
- Exports: `app/Exports/SaleByCustomerReportExport.php`, `PurchaseBySupplierReportExport.php`, `SaleReportExport.php`, and the purchase report export.
- Snapshot services that materialize report rows for the same four reports.
- Existing report tests: `SaleByCustomerReportTest`, `PurchaseBySupplierReportTest`, `SaleReportExportParityTest`, `PurchaseReportExportParityTest`, `SaleReportHardeningTest`, `PurchaseReportHardeningTest`.
- No database schema changes, no import-pipeline changes, no data migration.
