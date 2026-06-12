## Why

The Expense module currently records expenses through mixed legacy controller and Livewire paths without a formal approval lifecycle, strong setting ownership checks, or clear archive behavior. This hardening is needed so expense transactions are protected per setting, approved before affecting reports, and auditable when rejected or archived.

## What Changes

- Add an expense lifecycle with uppercase stored statuses: `DRAFT`, `SUBMITTED`, `APPROVED`, and `REJECTED`.
- Allow expense creation as either saved draft or submitted for approval, with new expenses defaulting to `DRAFT`.
- Add a dedicated expense show page for read-only review, attachments, detail rows, status, and permitted actions.
- Lock submitted and approved expenses from edits; rejected expenses can be edited and return to `DRAFT` while showing the rejection reason.
- Allow approval and rejection through `expenses.approval`; rejection requires a reason.
- Allow archiving through `expenses.archive`; archiving approved expenses requires a reason and hides them from normal lists and reports.
- Keep categories and taxes as global master data while enforcing setting ownership on all expense transactions.
- Keep expense detail item names as free text and provide suggestions from approved historical expense detail names.
- Make Livewire and controller writes share the same validation, setting scope, totals, file handling, and persistence rules.
- Generate expense references at draft creation using the current setting `document_prefix` plus `EXP`, with uniqueness enforced per setting.
- Preserve the legacy `expenses.details` column behavior while backfilling between it and `expense_details` where useful.
- Migrate existing expenses to `APPROVED` so historical reporting behavior is preserved.

## Capabilities

### New Capabilities

- `expense-approval-workflow`: Expense transaction lifecycle, setting-scoped access, approval/rejection/archive behavior, reporting inclusion, reference generation, detail suggestions, and category usage rules.

### Modified Capabilities

- None.

## Impact

- Affected source: `Modules/Expense`, `app/Livewire/Expense`, `resources/views/livewire/expense`, expense routes, permissions, reports that include expenses, and expense-related tests.
- Affected data: `expenses`, `expense_details`, `expense_categories`, and Spatie media records attached to expenses.
- Affected permissions: `expenses.access`, `expenses.create`, `expenses.edit`, `expenses.delete`, `expenses.approval`, `expenses.archive`, plus existing expense category permissions.
- Affected behavior: only approved, non-archived expenses count in normal expense reports; archived expenses are preserved but hidden by default.
