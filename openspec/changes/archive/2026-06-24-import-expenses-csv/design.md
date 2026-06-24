## Context

The Expense module already stores tenant-scoped expenses through `expenses.setting_id`, categories through `expense_categories.setting_id`, supplier links through `expenses.supplier_id`, and detail rows through `expense_details`. Existing expense creation flows use `ExpenseService`, which validates supplier ownership, computes totals from detail rows, and supports an approval workflow with `DRAFT`, `SUBMITTED`, `APPROVED`, and `REJECTED` states.

Purchase and Sales imports already use an asynchronous batch architecture: upload stores the source CSV, creates an import batch, stages normalized rows into import-row records, then processes rows in a queue job with row-level status and error visibility. Expense import should reuse that operational pattern while writing Expense-domain records.

The initial source file is `upload-data/expense/Expense-2026-S1.csv`. It has headers `Tanggal, Transaksi, Nomor, Kategori, Deskripsi, Supplier, Jumlah, Tax, Status, Sisa Tagihan`; rows parse as CSV after trimming stray tab characters in some fields. The file contains paid Expense rows that must all belong to `CV Tiga Nusa Computer`.

## Goals / Non-Goals

**Goals:**

- Import each valid CSV row as one approved Expense under the `CV Tiga Nusa Computer` setting.
- Create one Expense Detail row per imported row using the CSV category/description context and amount.
- Resolve missing suppliers and categories under the target setting during processing.
- Preserve row-level import traceability, status, and error messages.
- Prevent duplicate expenses when the same source `Nomor` is imported again for the target setting.
- Keep processing safe for focused SQLite tests and production MySQL/MariaDB.

**Non-Goals:**

- Do not build a generic multi-tenant expense import selector in this change.
- Do not import unpaid or partially paid expense payables; rows with nonzero `Sisa Tagihan` are invalid until the Expense module supports payable settlement for imports.
- Do not create cash/bank ledger payment rows; the current Expense model represents approved expenses, not settlement documents.
- Do not change existing manual Expense approval behavior or report semantics beyond including newly imported approved expenses naturally.

## Decisions

1. Use dedicated Expense import batch and row tables.

   Add `expense_import_batches` and `expense_import_rows`, modeled after purchase import tracking. This keeps uploads auditable, supports background processing, and gives users row-level feedback instead of failing the whole file on one bad row.

   Alternative considered: process the file synchronously in the upload request. That is simpler for a 47-row file but diverges from existing import behavior and would be less robust for future expense files.

2. Add a source identity field on `expenses`.

   Add nullable `imported_expense_number` and enforce uniqueness for `(setting_id, imported_expense_number)` when present. The CSV `Nomor` is unique in the source file and is the best idempotency key. Imported expenses can still use the existing auto-generated `reference` for ERP-facing document numbers.

   Alternative considered: write CSV `Nomor` into `reference`. That would preserve source numbers visibly but would bypass the established reference format and could conflict with generated references.

3. Resolve the target setting by company name.

   Processing must resolve `Setting` where `company_name` matches `CV Tiga Nusa Computer` case-insensitively. If no unique setting is found, the batch must fail before creating expenses.

   Alternative considered: use the active session setting from the uploading user. The user explicitly requires all imports to go to CV Tiga Nusa Computer, so relying on session state is too easy to misroute.

4. Create missing suppliers with deterministic placeholder fields.

   For each CSV supplier, find by `setting_id` and normalized `supplier_name`. If missing, create a supplier under the target setting with deterministic import placeholders for required contact/address fields. This satisfies existing supplier schema requirements while making imported placeholders recognizable.

   Alternative considered: leave `supplier_id` null. That loses useful supplier filtering/reporting and ignores the user's instruction to create missing suppliers.

5. Resolve or create categories within the target setting.

   For each CSV category, find by `setting_id` and normalized `category_name`. If missing, create it with an import description. The importer must not reuse a category from a different setting even if the name matches.

   Alternative considered: require categories to pre-exist. That would force manual setup and reduce the value of the import.

6. Import valid rows directly as approved expenses.

   Rows with `Status = Paid` and `Sisa Tagihan = 0` become `Expense::STATUS_APPROVED`. This matches the confirmed requirement that all imported rows should be approved and avoids sending historical rows through manual approval.

   Alternative considered: use `ExpenseService::saveExpense()` with `APPROVED`. That path enforces `expenses.approval` gate checks intended for interactive users. A dedicated import service can reuse the same normalization concepts while applying import-specific authorization and traceability.

7. Treat tax conservatively.

   The current file has `Tax = 0.0`. Import rows with zero tax using `tax_id = null` and `is_tax_included = false`. Nonzero tax should be invalid unless a later implementation defines a reliable mapping from source tax amount/rate to `taxes.id`.

   Alternative considered: derive tax IDs from the amount alone. That is ambiguous and risks incorrect financial reporting.

## Risks / Trade-offs

- Duplicate source number collision -> Use database uniqueness on `(setting_id, imported_expense_number)` and mark duplicate rows as skipped or invalid with a clear message.
- Ambiguous target setting -> Require exactly one `CV Tiga Nusa Computer` setting match before processing any rows.
- Placeholder supplier contact data pollutes master data -> Use recognizable deterministic placeholders and only create suppliers when absent.
- Cross-tenant category or supplier leakage -> Always resolve categories and suppliers with the target `setting_id`.
- Nonzero tax source rows could be misclassified -> Reject nonzero tax rows until explicit tax mapping exists.
- Approved imports bypass manual approval -> Scope the upload/process route to an import-capable Expense permission and record import batch/user metadata.

## Migration Plan

1. Add import tracking tables for expense batches and rows.
2. Add nullable `imported_expense_number` to `expenses` with an index/unique constraint scoped by `setting_id`.
3. Add Expense import entities, controller/routes/views, staging job, processing job, and service.
4. Deploy migrations before enabling the upload route.
5. Rollback by disabling the route/jobs first, then rolling back the new import tables and nullable source identity column if no imported production data must be retained.

## Open Questions

- Should duplicate source numbers be reported as `skipped` or `invalid` in the UI? Recommended default: `skipped`, because repeated import attempts are operationally normal and should not count as data errors.
- Should the import upload use existing `expenses.create` plus `expenses.approval`, or a new `expenses.import` permission? Recommended default: add `expenses.import` so bulk historical approval can be granted separately from regular creation/approval.
