## Context

Expense imports are staged into `expense_import_rows` and processed by `Modules\Expense\Services\ExpenseImportService`. The service already parses the CSV `Tanggal` field and persists it to `expenses.date`, but it creates expenses with an empty `reference` so the `Expense` model auto-generates one.

`Modules\Expense\Entities\Expense` currently generates references from `now()` and filters the previous sequence by `created_at`. That means historical imports processed later receive references for the processing month rather than the source transaction month. Sales and purchase models already use the document `date` for similar reference generation, so the expense behavior is the outlier.

The original expense import change required `Supplier`, but historical source files include paid expense rows where `Supplier` is blank and `Kategori` contains the most useful fallback label, such as `PLN`, `telkom`, or `PDAM`.

## Goals / Non-Goals

**Goals:**

- Generate new expense references using the expense date's year and month.
- Preserve setting-scoped monthly sequencing and existing reference format.
- Allow expense import rows with blank `Supplier` when `Kategori` is available.
- Use the resolved supplier name from `Supplier` first, falling back to `Kategori`.
- Keep imported expenses linked to a supplier for report filtering and import traceability.
- Cover both behaviors with focused automated tests.

**Non-Goals:**

- Do not rewrite references on existing expense records.
- Do not change sale, purchase, purchase return, POS, or adjustment reference generation.
- Do not make supplier optional for expense imports when both `Supplier` and `Kategori` are blank.
- Do not add new import columns, permissions, routes, or database tables.
- Do not support nonzero tax import rows as part of this fix.

## Decisions

1. Generate expense references from `expenses.date`.

   The `Expense` model creation hook should parse `$model->date` when present, falling back to `now()` only when no date is set. The latest reference lookup should filter by `date` year/month instead of `created_at` year/month. This matches the established `Sale` and `Purchase` model pattern and keeps manual and imported expenses consistent.

   Alternative considered: generate the reference inside `ExpenseImportService`. That would fix imports only, but manual backdated expenses would still receive references from the current month and the model would remain inconsistent with other document models.

2. Keep the existing reference format.

   References should continue to use the setting `document_prefix`, the `EXP` document marker, the date year/month, and the five-digit sequence. Only the source of the year/month and sequence bucket changes.

   Alternative considered: use the source CSV `Nomor` as `reference`. That would make imported documents mirror the source, but it conflicts with existing ERP reference conventions and bypasses the existing `imported_expense_number` identity field.

3. Resolve import supplier from `Supplier ?: Kategori`.

   `ExpenseImportService` should keep requiring `Kategori`, then derive the supplier lookup name as the trimmed `Supplier` value when present, otherwise the trimmed `Kategori` value. The existing `findOrCreateSupplier()` path can then reuse or create a supplier under the target setting using the same deterministic placeholder fields.

   Alternative considered: allow `supplier_id` to remain null. Manual expenses allow null suppliers, but the import design intentionally creates supplier links for report filtering and imported historical visibility. Falling back to category preserves that operational value.

4. Update the active specs, not only archived history.

   The archived expense import change documents the original import behavior, but no active `expense-csv-import` spec exists. This change should introduce an active import spec and modify the active `expense-approval-workflow` reference requirement.

   Alternative considered: rely only on tests. That would leave the import contract absent from active OpenSpec history and make the fallback rule easy to regress.

## Risks / Trade-offs

- Existing references are not backfilled -> Historical records already created with processing-month references will remain unchanged unless a separate audited data correction is proposed.
- Sequence ordering can be affected by insertion order for backdated records -> Continue using latest `id` within the expense date month, matching existing sale/purchase behavior and avoiding broad sequence refactors.
- Category fallback may create supplier names that are broad expense categories -> This is limited to blank supplier source rows and preserves reportability better than invalidating paid historical expenses.
- Existing tests may assume blank supplier is invalid -> Update those tests to reflect the new fallback rule while keeping missing category invalid.
- Concurrent creates in the same setting/month can still collide at the database unique constraint -> Preserve existing duplicate-reference handling behavior; broader locking/retry is out of scope.

## Migration Plan

1. Deploy code and tests without schema changes.
2. New expenses created after deployment use the date-based reference bucket.
3. New imported rows with blank `Supplier` and present `Kategori` process successfully with supplier resolved from category.
4. Rollback by reverting the code change; no data migration rollback is required.

## Open Questions

- Should already-imported expenses with wrong reference month be corrected through a separate audited data repair change? This proposal intentionally leaves existing data untouched.
