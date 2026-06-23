## 1. Report Access and Navigation

- [ ] 1.1 Add focused tests proving `Pajak penjualan` appears as an actionable Reports > Pajak card for users with `reports.access`
- [ ] 1.2 Add focused tests proving the Pajak tab is shown for permitted users and hidden when no permitted Pajak report card exists
- [ ] 1.3 Add a sales tax report route and controller/view entry in `Modules/Reports` gated by `reports.access`
- [ ] 1.4 Update the Reports landing configuration so `Pajak penjualan` links to the new report route and no longer renders as a placeholder

## 2. Report State and Query Contract

- [ ] 2.1 Add sales tax report filter data and validator classes for start date, end date, period preset, active setting scope, and export snapshot hashing
- [ ] 2.2 Add a sales tax report query service that aggregates approved-or-later Sales detail tax rows as `Penjualan`
- [ ] 2.3 Add purchase-side aggregation to the query service for approved-or-later Purchase detail tax rows as `Pembelian`
- [ ] 2.4 Ensure the query service excludes drafted, waiting-approval, rejected, archived, out-of-range, out-of-setting, and non-tax rows
- [ ] 2.5 Ensure DPP is derived from persisted detail values as `max(0, sub_total - product_tax_amount)` and tax amount is never recomputed from current tax settings
- [ ] 2.6 Add query-service tests for tax name/rate distinction, setting scope, status exclusion, DPP math, sales/purchase grouping, and per-tax subtotal inputs

## 3. Livewire Report Page

- [ ] 3.1 Add a `SalesTaxReport` Livewire component with date fields, period presets, apply-filter behavior, and snapshot-validated export guards
- [ ] 3.2 Add the report Blade view with title `Laporan Pajak Penjualan`, currency note `(dalam IDR)`, filter controls, export controls, grouped table rows, subtotals, and empty state
- [ ] 3.3 Implement grouped display rows with tax group headers, indented `Penjualan`/`Pembelian` transaction rows, blank separators, and subtotal rows
- [ ] 3.4 Add Livewire tests for default dates, preset date ranges, invalid ranges, empty state, filter application, and stale-filter export blocking

## 4. CSV and XLSX Exports

- [ ] 4.1 Add a `SalesTaxReportExport` class that can emit flat CSV rows with headings `Nama Pajak`, `Transaksi`, `DPP`, `Rate Pajak`, and `Total Pajak`
- [ ] 4.2 Add XLSX export formatting with company name, report title, selected date range, `(dalam IDR)`, grouped tax headers, transaction rows, subtotal rows, blank separators, and two-decimal numeric formatting
- [ ] 4.3 Use filenames that identify `sales_tax_report` or `SalesTaxReport` plus the selected date range
- [ ] 4.4 Add export tests proving CSV omits metadata/subtotals and XLSX includes metadata/grouping/subtotals
- [ ] 4.5 Add export tests proving exports use the last successfully applied filter snapshot and reject unapplied filter drift

## 5. Verification

- [ ] 5.1 Run focused report/navigation tests for the new sales tax report and Reports landing changes
- [ ] 5.2 Run a focused `php artisan test` filter covering sales and purchase tax aggregation paths
- [ ] 5.3 Manually compare generated UI/CSV/XLSX output against `report-sample/pajak-penjualan` for the representative 2026 sample shape
- [ ] 5.4 Document any intentionally unsupported sample behavior, especially PDF export, in implementation notes or test names
