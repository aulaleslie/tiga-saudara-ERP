## 1. Access and Navigation

- [x] 1.1 Add a `SupplierPayablesReportController` and module route named `reports.supplier-payables.index` under the Reports routes, gated by `purchaseReports.access`.
- [x] 1.2 Add the `reports::supplier-payables.index` view that renders the `reports.supplier-payables-report` Livewire component and uses the title `Laporan Hutang Supplier`.
- [x] 1.3 Update the Reports landing Pembelian card metadata so `Utang supplier` links to `reports.supplier-payables.index` and no longer renders as a placeholder.
- [x] 1.4 Update Reports landing feature tests to assert authorized users see an actionable `Utang supplier` card and unauthorized users do not.

## 2. Report Data Services

- [x] 2.1 Create `SupplierPayablesReportFilterData` with as-of date, due-date-until, supplier IDs, tag IDs, tag logic, sort field, sort direction, period preset, and scoped setting ID.
- [x] 2.2 Create `SupplierPayablesReportValidator` for date, supplier, tag, tag logic, sort field, and sort direction validation.
- [x] 2.3 Create `SupplierPayablesReportQueryService` that builds invoice-grain purchase queries scoped to the active `setting_id`.
- [x] 2.4 Implement active payment aggregation using `purchase_payments.amount / 100.0`, `status = ACTIVE`, and payment `date <= as_of_date`.
- [x] 2.5 Compute `saldo` from purchase total minus active as-of payments and exclude invoices whose computed balance is not positive.
- [x] 2.6 Implement due-date-until, supplier, and tag all/any filters.
- [x] 2.7 Implement supplier-group sorting by supplier name and total remaining balance without interleaving supplier rows.
- [x] 2.8 Add row mappers for screen and export labels, including `Purchase Invoice`, `Jumlah`, and `Saldo`.

## 3. Snapshot and Livewire UI

- [x] 3.1 Create `SupplierPayablesReportSnapshot` and `SupplierPayablesReportSnapshotService` to guard exports against changed unapplied filters.
- [x] 3.2 Replace the empty `SupplierPayablesReport` Livewire shell with filter state, period preset handling, supplier/tag searchable selectors, apply/cancel/reset behavior, pagination, and export actions.
- [x] 3.3 Build the Blade report UI with sample-aligned heading, filter controls, export buttons, grouped supplier rows, invoice rows, supplier subtotal rows, grand total, empty state, validation display, and pagination.
- [x] 3.4 Ensure current-page grouped rendering carries supplier running totals correctly across paginated invoice rows.

## 4. Exports

- [x] 4.1 Create `SupplierPayablesReportExport` for XLSX, CSV, and PDF using the applied query and filter data.
- [x] 4.2 Emit sample-aligned export columns: `Supplier`, `Date`, `Transaksi`, `No.`, `Jatuh Tempo`, `Keterangan`, `Jumlah`, and `Saldo`.
- [x] 4.3 Include supplier group headers, supplier subtotal rows, grand total row, company/report/as-of/currency headers for spreadsheet/PDF exports, and numeric formatting for `Jumlah` and `Saldo`.
- [x] 4.4 Block exports with the existing alert pattern when filters have changed without regeneration.

## 5. Tests and Verification

- [x] 5.1 Add feature/Livewire tests for route authorization, default as-of date, supplier grouping, subtotals, grand totals, and empty supplier omission.
- [x] 5.2 Add query-service tests for back-dated payments, invalidated payments, payment amount scaling, positive-balance filtering, due-date filtering, supplier filtering, and tag all/any filtering.
- [x] 5.3 Add sorting tests for supplier name and total remaining balance.
- [x] 5.4 Add export tests for XLSX/CSV/PDF availability, export snapshot freshness, sample-aligned headings, subtotal rows, and grand total rows.
- [x] 5.5 Run focused report tests for supplier payables and reports landing.
- [x] 5.6 Run `openspec validate add-supplier-payables-report --strict`.
