## 1. Schema And Data Migration

- [x] 1.1 Add `status`, `rejection_reason`, `archived_at`, `archived_by`, and `archive_reason` columns to `expenses`.
- [x] 1.2 Add a database uniqueness constraint for `expenses.setting_id` plus `expenses.reference`.
- [x] 1.3 Backfill existing expenses to `APPROVED` status while preserving current amount, category, date, reference, attachments, and legacy details data.
- [x] 1.4 Backfill between legacy `expenses.details` and `expense_details` where one representation is missing and useful.
- [x] 1.5 Add or update model constants, casts, archive relationship, and query scopes for expense statuses and active approved expenses.

## 2. Permissions And Setting Ownership

- [x] 2.1 Add `expenses.approval` and `expenses.archive` to the permission configuration and seed/update role permission data consistently with existing permission patterns.
- [x] 2.2 Implement a reusable expense setting-ownership guard for controller and Livewire/service use.
- [x] 2.3 Apply ownership checks to expense show, edit, update, delete, submit, approve, reject, archive, and attachment mutation paths.
- [x] 2.4 Ensure role-per-setting authorization is evaluated against the current `session('setting_id')` for lifecycle actions.

## 3. Shared Expense Persistence

- [x] 3.1 Create a shared expense service for validating and persisting draft/update/submit payloads from both Livewire and controller routes.
- [x] 3.2 Normalize formatted amounts, require every detail row amount to be greater than zero, and compute header totals from detail rows plus applicable tax behavior.
- [x] 3.3 Hide and null tax fields when the current setting does not allow tax usage for expenses.
- [x] 3.4 Persist header, detail rows, attachment additions/removals, and lifecycle transitions inside database transactions with practical media cleanup on failure.
- [x] 3.5 Preserve current `expenses.details` behavior while keeping structured `expense_details` rows authoritative for detail-row operations.

## 4. Lifecycle Actions

- [x] 4.1 Implement save-draft and submit-for-approval actions for new expenses.
- [x] 4.2 Implement submit action for draft expenses and reject-to-draft behavior when rejected expenses are edited.
- [x] 4.3 Implement approve and reject actions for submitted expenses using `expenses.approval`, with required rejection reason.
- [x] 4.4 Implement archive action for submitted and approved expenses using `expenses.archive`, with required reason for approved expenses.
- [x] 4.5 Enforce hard-delete only for draft and rejected expenses, and block hard-delete for submitted and approved expenses.
- [x] 4.6 Enforce edit and attachment lock for submitted and approved expenses.

## 5. References

- [x] 5.1 Generate references at draft creation using `{document_prefix}-EXP-{YYYY}-{MM}-{00001}`.
- [x] 5.2 Scope reference sequences by current setting and calendar month.
- [x] 5.3 Handle duplicate reference races safely by retrying or returning a safe validation failure.

## 6. Expense UI

- [x] 6.1 Update the expense create/edit Livewire form to support save draft and submit for approval actions.
- [x] 6.2 Add a dedicated expense show page with header, category, date, reference, details, tax totals, attachments, status label, rejection reason, and lifecycle actions.
- [x] 6.3 Map uppercase statuses to Indonesian labels in all expense UI surfaces.
- [x] 6.4 Update expense index actions and filters for status, archived visibility, show/edit/delete/submit/approve/reject/archive actions, and permission-aware visibility.
- [x] 6.5 Add rejection and archive reason modals/forms where required, including index and show page actions.
- [x] 6.6 Add global approved-detail-name suggestions to expense detail name inputs.

## 7. Categories And Reports

- [x] 7.1 Keep expense categories global in category lists and expense dropdowns, ignoring legacy category `setting_id` for visibility.
- [x] 7.2 Scope expense category dropdowns in forms and reports to only show active categories.
- [x] 7.3 Filter global financial expense reports to only include `APPROVED` expenses (not draft, submitted, or rejected).
- [x] 7.4 Exclude archived expenses from primary report aggregations unless explicitly requested.
- [x] 7.5 Display rejection and archive reasons in audit-focused report exports where applicable.

## 8. Controller And Routes

- [x] 8.1 Remove `->except('show')` from the expenses resource route registration.
- [x] 8.2 Update controller store and update to accept structured details[], optional files, and draft-or-submit action, delegating to the shared expense service.
- [x] 8.3 Add controller actions/routes for submit, approve, reject, and archive.
- [x] 8.4 Enforce `idempotency` middleware on status-mutating actions (submit, approve, reject) where reasonable.

## 9. Tests

- [x] 9.1 Add migration/model tests for status defaults, archive metadata, reference uniqueness, and existing expense backfill behavior.
- [x] 9.2 Add feature tests for cross-setting show/edit/update/delete/submit/approve/reject/archive denial.
- [x] 9.3 Add Livewire tests for save draft, submit for approval, row amount validation, tax hiding for non-tax settings, attachment locking, and rejected edit returning to draft.
- [x] 9.4 Add controller route tests proving `store` and `update` use the same structured payload rules as Livewire.
- [x] 9.5 Add lifecycle tests for approve, reject with required reason, archive submitted, archive approved with required reason, and delete guards.
- [x] 9.6 Add expense show/index UI tests for visible actions, Indonesian status labels, filters, rejection/archive reason flows, and archived visibility.
- [x] 9.7 Add category tests for global visibility, current-setting usage counts, and global delete blocking.
- [x] 9.8 Add report/dashboard tests proving draft, submitted, rejected, and archived expenses are excluded while approved active expenses are included.
- [x] 9.9 Add suggestion tests proving only approved expense detail names are suggested.

## 10. Verification

- [x] 10.1 Run focused expense feature and Livewire tests.
- [x] 10.2 Run focused report tests impacted by expense totals.
- [x] 10.3 Run `php artisan test` or `composer test:fresh-sqlite` when practical for migration confidence.
- [x] 10.4 Manually review create draft, submit, approve, reject, edit rejected, archive, and report inclusion flows in a local browser if a dev server is available.
