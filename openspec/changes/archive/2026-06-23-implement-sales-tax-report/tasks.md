## 1. Report Access and Navigation

- [x] 1.1 Add focused tests proving `Pajak penjualan` appears as an actionable Reports > Pajak card for users with `reports.access`
- [x] 1.2 Add focused tests proving the Pajak tab is shown for permitted users and hidden when no permitted Pajak report card exists
- [x] 1.3 Add a sales tax report route and controller/view entry in `Modules/Reports` gated by `reports.access`
- [x] 1.4 Update the Reports landing configuration so `Pajak penjualan` links to the new report route and no longer renders as a placeholder

## 2. Report State and Query Contract

- [x] 2.1 Add `SalesTaxReportFilterData` (date range, period presets, scopeSettingId), `Validator`, and `Snapshot` / `SnapshotService` boilerplate.
- [x] 2.2 Add a sales tax report query service that aggregates approved-or-later Sales detail tax rows as `Penjualan`
- [x] 2.3 Add purchase-side aggregation to the query service for approved-or-later Purchase detail tax rows as `Pembelian`
- [x] 2.4 Ensure the query service excludes drafted, waiting-approval, rejected, archived, out-of-range, out-of-setting, and non-tax rows
- [x] 2.5 Ensure DPP is derived from persisted detail values as `max(0, sub_total - product_tax_amount)` and tax amount is never recomputed from current tax settings
- [x] 2.6 Add query-service tests for tax name/rate distinction, setting scope, status exclusion, DPP math, sales/purchase grouping, and per-tax subtotal inputs

### Phase 3: Livewire Report Page

- [x] 3.1 Add a `SalesTaxReport` Livewire component with date fields, period presets, apply-filter behavior, and snapshot-validated export guards
- [x] 3.2 Add the report Blade view with title `Laporan Pajak Penjualan`, currency note `(dalam IDR)`, filter controls, export controls, grouped table rows, subtotals, and empty state
- [x] 3.3 Implement grouped display rows with tax group headers, indented `Penjualan`/`Pembelian` transaction rows, blank separators, and subtotal rows
- [x] 3.4 Add Livewire tests for default dates, preset date ranges, invalid ranges, empty state, filter application, and stale-filter export blocking

### Phase 4: CSV and XLSX Exports

- [x] 4.1 Add a `SalesTaxReportExport` class that can emit flat CSV rows with headings `Nama Pajak`, `Transaksi`, `DPP`, `Rate Pajak`, and `Total Pajak`
- [x] 4.2 Add XLSX export formatting with company name, report title, selected date range, `(dalam IDR)`, grouped tax headers, transaction rows, subtotal rows, blank separators, and two-decimal numeric formatting
- [x] 4.3 Use filenames that identify `sales_tax_report` or `SalesTaxReport` plus the selected date range
- [x] 4.4 Add export tests proving CSV omits metadata/subtotals and XLSX includes metadata/grouping/subtotals
- [x] 4.5 Add export tests proving exports use the last successfully applied filter snapshot and reject unapplied filter drift

### Phase 5: Verification

- [x] 5.1 Run focused report/navigation tests for the new sales tax report and Reports landing changes
- [x] 5.2 Run a focused `php artisan test` filter covering sales and purchase tax aggregation paths
- [x] 5.3 Manually compare generated UI/CSV/XLSX output against `report-sample/pajak-penjualan` for the representative 2026 sample shape
- [x] 5.4 Document any intentionally unsupported sample behavior, especially PDF export, in implementation notes or test names
