## Why

Expense CSV imports currently create ERP references using the processing date instead of the imported expense date, so historical imports receive references for the wrong month. Older source files also contain valid expense rows with an empty `Supplier` column, and those rows fail even when `Kategori` contains the best available party/category label to use for import reporting.

## What Changes

- Generate expense references from the expense transaction date rather than `created_at` or the current clock date.
- Keep expense reference sequences scoped by setting, year, and month, but derive year/month from `expenses.date`.
- Allow expense CSV rows with blank `Supplier` when `Kategori` is present.
- Resolve or create the imported expense supplier using `Supplier` when present, otherwise using `Kategori`.
- Preserve existing validation for missing `Tanggal`, `Nomor`, `Kategori`, invalid dates, unpaid rows, nonzero outstanding balance, and unsupported tax rows.
- Add focused regression coverage for imported historical dates and blank supplier fallback.

## Capabilities

### New Capabilities
- `expense-csv-import`: Defines expense CSV import behavior, including accepted rows, supplier/category resolution, duplicate source number protection, and imported approved expense persistence.

### Modified Capabilities
- `expense-approval-workflow`: Expense reference generation must use the expense date for monthly sequencing rather than the persistence timestamp.

## Impact

- Affected code: `Modules/Expense/Entities/Expense.php`, `Modules/Expense/Services/ExpenseImportService.php`, and focused expense import/reference tests.
- Affected data: new imported expenses will receive references in the month/year of `expenses.date`; existing records are not rewritten.
- Affected specs: active expense lifecycle/reference behavior and newly captured expense CSV import behavior.
- No new external dependencies, routes, permissions, or database tables are expected.
