## Why

Expense records for early 2026 exist in CSV form and need to be brought into the ERP without manual entry. Importing them through the Expense module keeps reporting, approval state, supplier linkage, and tenant scoping consistent with the existing Laravel ERP data model.

## What Changes

- Add an expense CSV import workflow that accepts files shaped like `upload-data/expense/Expense-2026-S1.csv`.
- Import every accepted CSV row under the `CV Tiga Nusa Computer` setting.
- Resolve each CSV supplier within `CV Tiga Nusa Computer`; create the supplier when it does not already exist.
- Resolve or create required expense categories within `CV Tiga Nusa Computer`.
- Create one approved Expense and one Expense Detail row per imported CSV row.
- Treat the CSV `Nomor` as the source import identity so repeated imports do not create duplicate expenses.
- Reject or mark invalid rows that cannot be parsed, do not belong to transaction type `Expense`, have non-positive amounts, or conflict with an existing imported source identity.
- Provide batch and row-level visibility for import progress, successes, skipped rows, and validation errors.

## Capabilities

### New Capabilities

- `expense-csv-import`: Imports external expense CSV rows into approved tenant-scoped ERP Expense records while resolving categories, creating missing suppliers, and preventing duplicate source numbers.

### Modified Capabilities

- None.

## Impact

- Affected modules: `Modules/Expense`, with likely reuse of import patterns from `Modules/Purchase` and `Modules/Sale`.
- Affected data: `expenses`, `expense_details`, `expense_categories`, `suppliers`, and new expense import batch/row tracking tables.
- Affected permissions/routes/views: Expense upload, import status, and optional import list pages guarded by Expense permissions.
- Testing impact: focused feature and unit tests for CSV parsing, tenant resolution, supplier/category creation, approved expense creation, duplicate handling, and invalid row reporting.
