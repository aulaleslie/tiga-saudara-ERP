## 1. Persistence and Permissions

- [ ] 1.1 Add an SQLite/MySQL-compatible migration for the polymorphic append-only `due_date_audits` table with document, setting, actor, reason, prior/resulting date, timestamp, and lookup indexes.
- [ ] 1.2 Add the due-date audit model, casts, actor/auditable relationships, and ordered audit relationships on Purchase and Sale without altering existing reporting-date history.
- [ ] 1.3 Register `purchases.due-date.override` and `sales.due-date.override` in the established permission configuration and synchronization path without granting them to existing ordinary roles by default.
- [ ] 1.4 Add `overrideDueDate` Purchase and Sale policy abilities with the specified active-setting and eligible-status rules while preserving the global Super Admin bypass.

## 2. Atomic Date-Adjustment Domain Flow

- [ ] 2.1 Define a typed date-adjustment command/value contract that distinguishes reporting actions (`keep`, `set`, `clear`) and due-date actions (`keep`, `set`) from omitted fields.
- [ ] 2.2 Implement a shared transactional date-adjustment service that reloads and locks Purchase or Sale, reauthorizes each requested field, rejects an all-no-op request, and applies effective changes together.
- [ ] 2.3 Persist reporting-date and due-date audit entries in the same transaction, ensuring any authorization, update, or audit failure rolls back every requested field.
- [ ] 2.4 Accept any valid non-null due date without chronological comparisons, avoid an audit for an unchanged due date, and preserve `payment_term_id` plus all unrelated operational and financial fields.
- [ ] 2.5 Refactor the existing reporting-date service/endpoints to delegate to the shared transactional core where appropriate while preserving create, replace, clear, response, and audit compatibility.

## 3. Protected HTTP and Detail-Page Experience

- [ ] 3.1 Add module-specific validated Purchase and Sale endpoints for combined date adjustments with explicit field actions, a mandatory reason, and backend per-field authorization.
- [ ] 3.2 Update Purchase detail UI to expose one date-adjustment action to users with either ability, render only authorized controls, support reporting clear, and submit both effective changes together.
- [ ] 3.3 Update Sale detail UI with the same independently authorized combined interaction and unrestricted valid due-date selection.
- [ ] 3.4 Present clearly labeled due-date old/new audit history with actor, reason, and time alongside the existing reporting-date history, without exposing unauthorized controls.
- [ ] 3.5 Preserve existing global-mode and legacy reporting-date route behavior and provide actionable validation/authorization/no-op feedback without requiring a full document edit.

## 4. Authorization and Domain Verification

- [ ] 4.1 Add Purchase feature tests for dedicated permission, reporting-only denial, active-setting isolation, every eligible and ineligible status, Super Admin bypass, valid dates before/equal/after transaction date, missing inputs, and no-op handling.
- [ ] 4.2 Add equivalent Sale feature tests for the independent sales due-date permission and unrestricted calendar-date replacement.
- [ ] 4.3 Add audit tests for old/new due dates, actor, setting, reason, repeated immutable history, model relationships, and preservation of the payment-term reference and unrelated document facts.
- [ ] 4.4 Add combined-operation tests proving reporting-only, due-only, both-field success, per-field tampering denial, lock-time authorization, and full rollback when either update or audit insertion fails.

## 5. UI and Due-Date Consumer Regression Coverage

- [ ] 5.1 Add Purchase and Sale detail-view tests for users with neither permission, each individual permission, both permissions, current date values, adjustment controls, and labeled audit history.
- [ ] 5.2 Add Purchase regressions proving replacement due dates drive overdue summaries, list filters, payment/detail/print presentation, Primary Purchase Report due-date filtering, Supplier Payables, exports, and due-date-based Aged Payables.
- [ ] 5.3 Add Sales regressions proving replacement due dates drive overdue summaries, list filters, payment presentation, Customer Receivables filtering/display/export, while transaction-date-based Aged Receivables remains unchanged.
- [ ] 5.4 Run focused date-adjustment, reporting-date, permission, Livewire/view, and report-boundary tests, then run `composer test:fresh-sqlite` and resolve any regressions.
