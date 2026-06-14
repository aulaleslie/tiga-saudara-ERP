## Context

The Expense module is a brownfield Laravel module with module routes/controllers, a Livewire 3 form, Eloquent models, Spatie media attachments, and Yajra DataTables. Current create/edit screens mount `App\Livewire\Expense\ExpenseForm`, while RESTful `store` and `update` routes still exist in `Modules/Expense\Http\Controllers\ExpenseController`. Expense detail rows are persisted in `expense_details`, but the legacy `expenses.details` column still exists and is displayed by the expense table.

The agreed domain rule is that expense categories and taxes are global master data, while expense transactions are setting-owned. A user can have different roles per setting, so transaction reads and mutations must enforce the current `session('setting_id')` in addition to permission checks.

## Goals / Non-Goals

**Goals:**

- Add an expense approval lifecycle without expanding into purchase, sale, POS, or product-master behavior.
- Preserve global expense categories and global taxes while hardening setting-scoped expense transactions.
- Make all expense write paths use the same validation, totals, reference generation, lifecycle, and persistence rules.
- Add a dedicated expense show page for approval review and read-only inspection.
- Ensure only approved, non-archived expenses affect normal reports.
- Preserve current legacy fields where possible, including `expenses.details`, while making detail rows the structured data source.

**Non-Goals:**

- Do not convert expense detail items into products or inventory-affecting records.
- Do not introduce per-setting category ownership.
- Do not introduce accounting journal generation for approved expenses.
- Do not add a general approval engine shared across modules.
- Do not add approved expense editing; approved expenses are archived instead.

## Decisions

### Expense Lifecycle

Expenses will store uppercase statuses: `DRAFT`, `SUBMITTED`, `APPROVED`, and `REJECTED`. Archived state will not be a status; it will be represented by archive metadata (`archived_at`, `archived_by`, `archive_reason`) so the lifecycle status remains visible after archival.

Alternatives considered:

- `ARCHIVED` as a status was rejected because it hides whether the expense was approved, submitted, or rejected before archive.
- Lowercase status values were rejected because the existing application commonly stores human-readable status values and the user requested uppercase internally.

### Approval Actions

The create form will support two submit actions: save as draft and submit for approval. Draft and rejected expenses can be edited; editing a rejected expense returns it to `DRAFT` and keeps the rejection reason visible to explain why it was rejected. Submitted and approved expenses are locked from attachment and detail changes.

Approval and rejection require `expenses.approval`. Rejection requires a reason. Approvers can approve their own expenses if they hold the approval permission in the current setting. Approved expenses cannot be edited; they can only be archived by `expenses.archive`.

Alternatives considered:

- Blocking self-approval was rejected because the current role-per-setting model allows the business to decide trust through permissions.
- Adding approved edit permission was rejected because the desired behavior is archive and recreate/correct later.

### Archive Behavior

Submitted and approved expenses can be archived only with `expenses.archive`. Approved archive requires a reason. Archived expenses are hidden from normal lists and excluded from normal reports, while preserved for audit/review. Draft and rejected expenses can be hard-deleted.

Alternatives considered:

- Treating archive as soft delete was rejected because expense archives need explicit reason and normal report exclusion.
- Allowing `expenses.edit` to withdraw submitted expenses was rejected in favor of stricter `expenses.archive` control.

### Setting Ownership

All transaction routes and Livewire actions must verify that the target expense belongs to the current setting before show, edit, update, delete, submit, approve, reject, archive, attachment removal, or file mutation. Permission checks remain necessary but are not sufficient.

Implementation should prefer a reusable guard or service method so controller and Livewire code cannot drift.

### Shared Expense Persistence Service

Create and update behavior should be centralized behind an application service used by both Livewire and controller routes. The service should validate setting context, normalize formatted amounts, compute tax totals, persist header/detail rows, handle files, preserve `expenses.details`, and apply lifecycle transitions inside database transactions.

This avoids the current split where Livewire writes structured detail rows and the controller accepts legacy header fields.

### References

References will be generated at draft creation using the current setting document prefix and expense prefix: `{document_prefix}-EXP-{YYYY}-{MM}-{00001}`. The sequence is scoped per setting and month, and uniqueness is enforced by `setting_id + reference`.

This keeps expense references aligned with existing setting document-prefix conventions without adding a new setting column.

### Categories, Taxes, And Suggestions

Expense categories remain global. Category lists and dropdowns show global categories. Category deletion is blocked when the category is used by any expense in any setting. Category usage counts shown to normal users should reflect the current setting, while delete guards still check global usage.

Tax options are global but hidden on the expense form when the current setting should not use tax. Expense item names remain free text. Suggestions are sourced globally from approved historical `expense_details.name` values.

### Reports

Normal reports, dashboards, and payment-sent calculations that include expenses should count only expenses where `status = APPROVED` and `archived_at IS NULL`. Archived approved expenses are excluded from normal reporting.

## Risks / Trade-offs

- Global detail suggestions may expose item names used by other settings → Accepted by product decision; limit the source to approved expenses to avoid draft/rejected noise.
- Status-only approval metadata limits auditability → Keep required rejection/archive reasons now; consider submitted/approved actor/timestamp fields later if audit requirements increase.
- Media writes are outside database rollback → Wrap database changes in transactions and clean up newly written files on failure where practical.
- Reference generation can race under concurrent creates → Enforce `setting_id + reference` uniqueness and retry sequence generation on duplicate key failures.
- Preserving `expenses.details` keeps two representations of detail text → Treat it as legacy-compatible data and avoid making it the only source of structured row behavior.

## Migration Plan

1. Add expense lifecycle and archive columns to `expenses`.
2. Backfill existing expenses as `APPROVED` and preserve/report existing data as approved historical expenses.
3. Backfill between `expenses.details` and `expense_details` where useful, noting that the business expects little or no existing expense usage.
4. Add database uniqueness for `setting_id + reference`.
5. Add `expenses.approval` and `expenses.archive` permissions.
6. Update reports to filter approved, non-archived expenses.
7. Rollback strategy: remove new UI actions first, then revert report filters and schema changes only after confirming no new lifecycle data needs preservation.

## Open Questions

- Whether to add submitted/approved actor and timestamp metadata in a later audit-focused change.
- Whether to add a future correction workflow for approved expenses instead of archive-and-recreate.
