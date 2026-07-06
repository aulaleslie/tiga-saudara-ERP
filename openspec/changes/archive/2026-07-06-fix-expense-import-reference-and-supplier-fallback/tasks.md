## 1. Reference Generation

- [x] 1.1 Add focused test coverage proving an expense dated in a prior month receives a reference for the expense date's year and month, not the current processing month.
- [x] 1.2 Add focused test coverage proving reference sequence lookup uses existing expenses from the same setting and same expense-date month.
- [x] 1.3 Update `Modules\Expense\Entities\Expense` reference generation to derive year/month from `$model->date` when present.
- [x] 1.4 Update the latest-reference lookup to filter by `date` year/month instead of `created_at` year/month while preserving the existing reference format and setting scope.

## 2. Expense Import Supplier Fallback

- [x] 2.1 Replace the existing blank-supplier import regression test with a processed-row expectation for `Supplier` blank and `Kategori` present.
- [x] 2.2 Add assertions that the fallback import creates or reuses a supplier named from `Kategori` and links the imported expense to it.
- [x] 2.3 Keep or add coverage proving blank `Kategori` remains invalid and does not create an expense.
- [x] 2.4 Update `Modules\Expense\Services\ExpenseImportService` to derive supplier lookup name from `Supplier` when present, otherwise from `Kategori`.
- [x] 2.5 Ensure category resolution, detail fallback naming, duplicate source number handling, and existing paid/tax/outstanding validations remain unchanged.

## 3. Verification

- [x] 3.1 Run the focused expense import/reference tests.
- [x] 3.2 Run the broader expense import feature test file.
- [x] 3.3 Run OpenSpec validation for `fix-expense-import-reference-and-supplier-fallback`.
