## 1. Expense Data Model

- [x] 1.1 Add a nullable `supplier_id` column to `expenses` with a safe foreign key to `suppliers.id` and rollback support.
- [x] 1.2 Update `Modules\Expense\Entities\Expense` with a `supplier()` relationship and Spatie taggable support.
- [x] 1.3 Ensure existing expenses remain valid with no supplier and no tags after migration.
- [x] 1.4 Add or update model/query helpers needed to load supplier, category, detail rows, and tags without N+1 queries.

## 2. Expense Persistence and UI

- [x] 2.1 Extend shared expense validation to accept optional current-setting `supplier_id` and optional tag IDs/names.
- [x] 2.2 Update `ExpenseService::saveExpense` to persist supplier and sync tags inside the existing transaction.
- [x] 2.3 Update controller create/update paths to pass supplier and tag payloads into the shared service.
- [x] 2.4 Update the Livewire expense form to search/select/remove supplier and tags.
- [x] 2.5 Hydrate supplier and tag selections when editing existing draft or rejected expenses.
- [x] 2.6 Display supplier and tags on expense show and expense index/list surfaces where the current UI has room.
- [x] 2.7 Verify submitted and approved expenses cannot mutate supplier or tags through normal edit paths.

## 3. Report Routing and Shell

- [x] 3.1 Add an Expense List report controller and module view under `Modules/Reports`.
- [x] 3.2 Add a `reports.expense-list.index` route under `/reports`, gated by `purchaseReports.access`.
- [x] 3.3 Add `App\Livewire\Reports\ExpenseListReport` and its Blade view using the existing report layout patterns.
- [x] 3.4 Initialize default filters to the current month and require applying filters before export.

## 4. Report Query and Mapping

- [x] 4.1 Create `ExpenseListReportFilterData`, validator, query service, snapshot, and snapshot service classes.
- [x] 4.2 Implement current-setting, approved, non-archived, inclusive date-range filtering.
- [x] 4.3 Implement supplier filtering, excluding null-supplier expenses only when a supplier filter is active.
- [x] 4.4 Implement tag filtering with `Mencakup Semua` and `Salah Satu` semantics.
- [x] 4.5 Implement sortable columns for date, total amount, paid/status, and outstanding amount with deterministic tie-breaking.
- [x] 4.6 Implement a shared row mapper for `Tanggal`, `Transaksi`, `Nomor`, `Kategori`, `Deskripsi`, `Supplier`, `Jumlah`, `Tax`, `Status`, and `Sisa Tagihan`.
- [x] 4.7 Implement tax amount calculation for tax-included and tax-excluded expenses consistent with expense persistence.
- [x] 4.8 Implement summary-mode totals and detail-mode totals without double-counting multi-detail expenses.

## 5. Report UI

- [x] 5.1 Render top-level date filters, Filter, Filter lainnya, and export controls.
- [x] 5.2 Render advanced supplier and tag filters with selected-value pills and AND/OR tag logic.
- [x] 5.3 Render sort column and sort direction controls.
- [x] 5.4 Render the `Perlihatkan Lebih Detail` toggle.
- [x] 5.5 Render the report table in summary mode with sample-compatible columns and localized display formatting.
- [x] 5.6 Render detail mode by expanding expenses into `expense_details` rows while preserving parent header context.
- [x] 5.7 Render empty, validation-error, loading, and pagination states consistent with existing reports.

## 6. Exports

- [x] 6.1 Add `ExpenseListReportExport` for XLSX, CSV, and PDF download paths.
- [x] 6.2 Export CSV as clean comma-separated content with sample-compatible columns and raw numeric values.
- [x] 6.3 Export XLSX with company name, `Daftar Pengeluaran`, selected date range, headers, data rows, and `Total Biaya`.
- [x] 6.4 Export PDF using the same applied filters, row mapping, and totals as the table.
- [x] 6.5 Ensure exports use snapshot validation and block stale/unapplied filter state.

## 7. Reports Landing Navigation

- [x] 7.1 Replace the Pembelian tab's disabled Daftar pengeluaran placeholder with an actionable report card.
- [x] 7.2 Gate the card with `purchaseReports.access`.
- [x] 7.3 Verify clicking the card and its call-to-action opens the new report route.

## 8. Verification

- [x] 8.1 Add migration/model tests for nullable supplier, supplier relationship, and legacy expense compatibility.
- [x] 8.2 Add expense create/edit tests for supplier persistence, tag sync, current-setting supplier rejection, and validation rollback.
- [x] 8.3 Add Livewire form tests for selecting, hydrating, removing, and displaying supplier and tags.
- [x] 8.4 Add report authorization and landing navigation tests.
- [x] 8.5 Add query-service tests for date range, current-setting isolation, approved/non-archived filtering, supplier filtering, tag AND/OR filtering, and sorting.
- [x] 8.6 Add summary/detail mode tests for row mapping, null supplier placeholder, tax values, `Paid` status, zero outstanding amount, and totals.
- [x] 8.7 Add export tests for CSV/XLSX/PDF row parity, clean CSV formatting, XLSX heading rows, and export snapshot guard.
- [x] 8.8 Run focused report and expense tests, then run `php artisan test` or `composer test:fresh-sqlite` when practical.
