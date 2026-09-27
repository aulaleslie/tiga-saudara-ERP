## 1. Test Coverage

- [x] 1.1 Add a focused Expense import test proving two rows with the same `Nomor` create one Expense with two Expense Details.
- [x] 1.2 Add a test proving the grouped Expense amount equals the sum of each row's `Jumlah` and batch `success_count` remains row-based.
- [x] 1.3 Add a test proving the first row's `Tanggal`, `Kategori`, and supplier fallback determine the Expense header when later grouped rows differ.
- [x] 1.4 Replace the nonzero-tax rejection test with coverage proving parseable nonzero `Tax` values are ignored and do not block import.
- [x] 1.5 Add or update coverage proving unparseable `Tax` still marks the group invalid and creates no Expense.
- [x] 1.6 Add or update duplicate source-number coverage proving a previously imported `Nomor` skips every row in that group and increments `skipped_count` by row count.

## 2. Import Grouping

- [x] 2.1 Refactor `ExpenseImportService::processBatch()` to load pending rows ordered by `row_number` and process them grouped by trimmed CSV `Nomor`.
- [x] 2.2 Add group-level validation that validates every row before creating any Expense or Expense Detail for the group.
- [x] 2.3 Preserve existing batch failure behavior when the target `CV Tiga Nusa Computer` setting cannot be resolved.
- [x] 2.4 Ensure invalid groups mark all affected rows invalid with a useful error message and do not create partial records.

## 3. Expense Creation

- [x] 3.1 Create one approved Expense per valid `Nomor` group using first-row header fields for date, category, supplier fallback, details summary, and `imported_expense_number`.
- [x] 3.2 Create one Expense Detail per grouped source row using row `Deskripsi` when present, otherwise row `Kategori`, and row `Jumlah` as the detail amount.
- [x] 3.3 Sum grouped row `Jumlah` values into the Expense header amount.
- [x] 3.4 Parse `Tax` for each row but ignore parseable tax values, leaving imported expenses tax-neutral (`is_tax_included = false`, detail `tax_id = null`).
- [x] 3.5 Mark every successfully imported row in the group processed and link each row to the created Expense.

## 4. Duplicate Handling And Counts

- [x] 4.1 Preserve duplicate source-number protection against Expenses that already exist before the group is processed.
- [x] 4.2 Mark every row in a duplicate group skipped with the existing duplicate message and increment `skipped_count` by grouped row count.
- [x] 4.3 Increment `success_count`, `error_count`, `skipped_count`, and `processed_rows` by source-row counts rather than Expense document counts.

## 5. Verification

- [x] 5.1 Run the focused Expense import test class.
- [x] 5.2 Run a fresh SQLite focused pass for Expense import if the direct test command reuses stale database state.
- [x] 5.3 Manually review that no route, UI, permission, migration, manual Expense form, or report behavior changed outside the import path.
