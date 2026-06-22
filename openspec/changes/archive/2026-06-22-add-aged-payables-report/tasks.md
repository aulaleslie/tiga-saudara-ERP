## 1. Report Entry Point

- [x] 1.1 Add `AgedPayablesReportController` and a `reports.aged-payables.index` route gated by `purchaseReports.access`.
- [x] 1.2 Add the Reports module index view that renders the aged payables Livewire component and uses the report title `Hutang` / `Usia utang`.
- [x] 1.3 Update the Reports landing Pembelian card configuration so `Usia utang` links to the aged payables route and no longer uses placeholder treatment.

## 2. Filter and Query Services

- [x] 2.1 Create `AgedPayablesReportFilterData` with as-of date, period preset, aging basis, supplier IDs, tag IDs, tag logic, sort field, sort direction, and scope setting ID.
- [x] 2.2 Create `AgedPayablesReportValidator` covering required as-of date, supported aging basis values, supplier/tag existence, tag logic, and sort options.
- [x] 2.3 Create `AgedPayablesReportQueryService` that aggregates purchase invoice balances by supplier using active purchase payments dated on or before the as-of date.
- [x] 2.4 Implement transaction-date and due-date aging bucket expressions for SQLite and MySQL/MariaDB, including due-date fallback to purchase date when null.
- [x] 2.5 Implement supplier filtering, tag all/any filtering, tenant scoping, positive rounded balance filtering, and sorting by supplier name or total balance.
- [x] 2.6 Add `AgedPayablesReportSnapshot` and `AgedPayablesReportSnapshotService` following the existing report export freshness pattern.

## 3. Livewire UI

- [x] 3.1 Create `App\Livewire\Reports\AgedPayablesReport` with pending/applied filter state, searchable supplier and tag selection, period preset handling, filter reset/cancel behavior, pagination, grand totals, and export actions.
- [x] 3.2 Create the Livewire Blade view with top-level `Per` date and Filter controls, advanced drawer filters for aging basis, supplier, tags, and sorting, and an export dropdown.
- [x] 3.3 Render one row per vendor with `Vendor`, `Total`, `1 - 30 Hari`, `31 - 60 Hari`, `61 - 90 Hari`, `> 90 Hari`, plus a `Total Hutang` grand total row.
- [x] 3.4 Add empty, validation-error, loading, and export-blocked states consistent with existing report components.

## 4. Exports

- [x] 4.1 Create `AgedPayablesReportExport` for XLSX, CSV, and PDF using the same applied query result as the UI.
- [x] 4.2 Ensure CSV export uses a plain header row with `Vendor`, `Total`, `1 - 30 Hari`, `31 - 60 Hari`, `61 - 90 Hari`, and `> 90 Hari`.
- [x] 4.3 Ensure XLSX/PDF exports include company name, `Hutang`, selected as-of date, `(dalam IDR)`, numeric bucket columns, and `Total Hutang`.
- [x] 4.4 Ensure export actions are blocked before filters are applied and after pending filters diverge from the last applied snapshot.

## 5. Tests

- [x] 5.1 Add route and landing navigation tests for authorized and unauthorized users, including the actionable `Usia utang` Pembelian card.
- [x] 5.2 Add query service tests for as-of cutoff, active versus invalidated payments, purchase payment amount scaling, tenant scoping, zero-balance exclusion, and supplier/tag filters.
- [x] 5.3 Add aging bucket tests for transaction-date basis, due-date basis, null due-date fallback, and boundary ages 0, 30, 31, 60, 61, 90, and 91 days.
- [x] 5.4 Add Livewire tests for applying filters, sorting by supplier and total balance, grand totals, reset/cancel behavior, and export snapshot blocking.
- [x] 5.5 Add export tests for CSV headers and rows, XLSX metadata and grand total, and PDF export parity where existing test tooling supports it.

## 6. Verification

- [x] 6.1 Run focused aged payables, reports landing, and export tests with `php artisan test --filter=...`.
- [x] 6.2 Run a broader report-focused test pass or `composer test:fresh-sqlite` when the focused suite is green.
- [x] 6.3 Manually compare the implemented report shape against `report-sample/usia-utang` for labels, filters, bucket columns, total row, and export metadata.
