## 1. Schema And Models

- [x] 1.1 Add migrations for `expense_import_batches` and `expense_import_rows` with batch status counters, row status, raw JSON, error message, and nullable `expense_id`.
- [x] 1.2 Add a nullable `imported_expense_number` column to `expenses` with target-setting-scoped duplicate protection for non-null imported values.
- [x] 1.3 Create `ExpenseImportBatch` and `ExpenseImportRow` entities with status constants, casts, and relationships to user, rows, and expense.
- [x] 1.4 Update the `Expense` entity fillable/guarded usage only as needed so imported source numbers can be persisted safely.

## 2. Import Parsing And Staging

- [x] 2.1 Add an Expense import upload controller that stores CSV files, hashes source files, creates batches, validates required headers, and dispatches row staging.
- [x] 2.2 Implement `StageExpenseImportRows` using `League\Csv\Reader`, preserving the existing purchase/sales import pattern while trimming stray tab characters and normalizing headers.
- [x] 2.3 Map CSV rows into canonical keys for `tanggal`, `transaksi`, `nomor`, `kategori`, `deskripsi`, `supplier`, `jumlah`, `tax`, `status`, and `sisa_tagihan`.
- [x] 2.4 Record staged row numbers and raw normalized JSON in `expense_import_rows` with pending status.

## 3. Domain Processing

- [x] 3.1 Implement an `ExpenseImportService` that resolves exactly one `CV Tiga Nusa Computer` setting before row processing.
- [x] 3.2 Validate row eligibility: transaction type `Expense`, paid status, zero remaining bill, positive amount, parseable date, zero tax, required category, required supplier, and required source number.
- [x] 3.3 Resolve or create expense categories within the target setting without reusing categories from another setting.
- [x] 3.4 Resolve or create suppliers within the target setting using deterministic import placeholder values for required supplier fields.
- [x] 3.5 Create one approved Expense and one Expense Detail for each valid row, storing the CSV `Nomor` as the imported source identity.
- [x] 3.6 Detect existing imported source numbers for the target setting and mark duplicate rows as skipped without creating additional expenses.
- [x] 3.7 Update batch counters and row statuses for processed, skipped, invalid, and failed outcomes.

## 4. Routes, Permissions, And Views

- [x] 4.1 Add an `expenses.import` permission to the central permissions configuration and seed/update path.
- [x] 4.2 Add authenticated Expense import routes for upload, batch list/status, and row status details.
- [x] 4.3 Add an Expense import entry point from the existing Expenses UI, visible only to users with import permission.
- [x] 4.4 Add import upload and batch status views following existing CoreUI/Bootstrap and purchase import status conventions.

## 5. Verification

- [x] 5.1 Add focused tests for parsing `Expense-2026-S1.csv` shape, including stray tab trimming.
- [x] 5.2 Add tests that all valid rows import under `CV Tiga Nusa Computer` as approved expenses with one detail row each.
- [x] 5.3 Add tests for missing supplier creation and existing supplier reuse scoped to the target setting.
- [x] 5.4 Add tests for missing category creation and existing category reuse scoped to the target setting.
- [x] 5.5 Add tests for duplicate `Nomor` handling so re-imports do not create duplicate expenses.
- [x] 5.6 Add tests for invalid rows: unpaid status, nonzero `Sisa Tagihan`, non-Expense transaction type, non-positive amount, missing required fields, and nonzero tax.
- [x] 5.7 Run focused Expense import tests with SQLite and, if practical, `php artisan test` filters covering the new import flow.
