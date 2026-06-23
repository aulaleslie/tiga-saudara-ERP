## 1. Report Wiring

- [x] 1.1 Add an `ExpenseDetailsReportController` that renders a new reports module view for Detail pengeluaran.
- [x] 1.2 Register a `reports.expense-details.index` route under `/reports`, gated by `can:purchaseReports.access`.
- [x] 1.3 Add a module view that mounts the new Livewire report component.
- [x] 1.4 Convert the Pembelian `Detail pengeluaran` landing card from placeholder to actionable route while preserving `purchaseReports.access` gating.

## 2. Filter And Snapshot Services

- [x] 2.1 Create `ExpenseDetailsReportFilterData` with start date, end date, category IDs, tag IDs, tag logic, sort direction, and scope setting ID.
- [x] 2.2 Create `ExpenseDetailsReportValidator` for date range, category IDs, tag IDs, tag logic, and sort direction.
- [x] 2.3 Create `ExpenseDetailsReportSnapshot` and `ExpenseDetailsReportSnapshotService` using the existing report snapshot guard pattern.
- [x] 2.4 Ensure export validation rejects missing or stale applied filters.

## 3. Query And Row Mapping

- [x] 3.1 Create `ExpenseDetailsReportQueryService` that loads approved, non-archived expenses for the current setting and date range.
- [x] 3.2 Implement category filtering using `expenses.category_id`.
- [x] 3.3 Implement tag filtering with `Mencakup Semua` and `Salah Satu` semantics.
- [x] 3.4 Apply deterministic sorting by category, expense date in selected direction, and expense ID.
- [x] 3.5 Implement a row mapper that emits one transaction row per expense with `Kategori / Tanggal`, `Transaksi`, `Nomor`, `Keterangan`, and `Jumlah`.
- [x] 3.6 Implement grouped summary data with category headers, category subtotals, grand total, and transaction count.

## 4. Livewire UI

- [x] 4.1 Create `App\Livewire\Reports\ExpenseDetailsReport` with default current-month date filters.
- [x] 4.2 Add date inputs, category search/select/remove controls, tag search/select/remove controls, tag logic, sort direction, filter action, and export actions.
- [x] 4.3 Render the title `Rincian Biaya` and currency note `(dalam IDR)`.
- [x] 4.4 Render category-grouped rows with subtotals, UI `Grand Total`, and `Menampilkan total dari <n> baris transaksi`.
- [x] 4.5 Render an empty state without totals when no matching expenses exist.
- [x] 4.6 Keep table formatting safe for long `Keterangan` values and localized IDR display amounts.

## 5. Exports

- [x] 5.1 Create `ExpenseDetailsReportExport` or equivalent export classes that reuse the query/row/group mapper.
- [x] 5.2 Implement CSV export with only five headers and flat one-row-per-expense data.
- [x] 5.3 Implement XLSX export with company name, `Rincian Biaya`, date range, `(dalam IDR)`, grouped rows, category subtotals, and `Grand Total Biaya`.
- [x] 5.4 Implement PDF export with the grouped report structure and `Grand Total Biaya`.
- [x] 5.5 Use filenames that identify `expense_details` and the selected date range for CSV, XLSX, and PDF.

## 6. Tests

- [x] 6.1 Add route/access tests for authorized and unauthorized Detail pengeluaran access.
- [x] 6.2 Add landing-page tests proving the Pembelian `Detail pengeluaran` card is actionable for `purchaseReports.access` users.
- [x] 6.3 Add query-service tests for approved/non-archived inclusion, draft/submitted/rejected/archive exclusion, current-setting isolation, date filtering, category filtering, tag AND/OR filtering, and sorting.
- [x] 6.4 Add mapper/grouping tests for one row per expense, `expenses.details` as `Keterangan`, category subtotals, grand total, and transaction count.
- [x] 6.5 Add Livewire tests for default filters, applying filters, empty state, grouped rendering, and stale export blocking.
- [x] 6.6 Add export tests for CSV flat shape and raw numeric values.
- [x] 6.7 Add export tests for XLSX/PDF grouped structure and `Grand Total Biaya` label.

## 7. Verification

- [x] 7.1 Run the focused report and Livewire tests for the new Detail pengeluaran report.
- [x] 7.2 Run existing ExpenseListReport tests to verify Daftar Pengeluaran behavior remains unchanged.
- [x] 7.3 Run ReportsLandingTest or the focused landing-navigation tests affected by the card change.
