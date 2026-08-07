## 1. Persistence and permissions

- [ ] 1.1 Add additive nullable `reporting_date` migrations for purchases and sales, plus the indexed append-only reporting-date audit table.
- [ ] 1.2 Add reporting-date audit and effective-date model relationships/accessors for Purchase and Sale without changing their original `date` behavior.
- [ ] 1.3 Register `purchases.reporting-date.override` and `sales.reporting-date.override` through the existing permission configuration and sync workflow.

## 2. Authorization and date-only update workflow

- [ ] 2.1 Implement tenant-scoped authorization/policy checks for the dedicated permissions and approved-or-later purchase/sale statuses.
- [ ] 2.2 Implement an atomic reporting-date override service that locks the document, validates a valid date or explicit clear plus required reason, saves only the override, and appends its audit entry.
- [ ] 2.3 Add protected purchase and sale endpoints/actions and standard UI feedback for setting, replacing, and clearing overrides.
- [ ] 2.4 Ensure the workflow accepts past, present, and future dates and never recalculates or validates `due_date` against the override.

## 3. Operational document presentation

- [ ] 3.1 Show the effective date (`reporting_date ?? date`) as the normal date on purchase operational list and detail views.
- [ ] 3.2 Show the effective date (`reporting_date ?? date`) as the normal date on sale operational list and detail views.
- [ ] 3.3 Add readable document audit/history presentation for original date, prior/resulting overrides, reason, actor, and timestamp.
- [ ] 3.4 Keep report queries, report exports, aging, inventory, stock mutation, valuation, and general-ledger presentation on their existing date sources.

## 4. Verification

- [ ] 4.1 Add purchase feature tests for lifecycle and setting authorization, create/replace/clear flows, required reason, valid past/future dates, and immutable audit data.
- [ ] 4.2 Add sales feature tests for lifecycle and setting authorization, create/replace/clear flows, required reason, valid past/future dates, and immutable audit data.
- [ ] 4.3 Add regression tests proving date-only overrides preserve original date, due date, reference, payments, statuses, receiving/dispatch data, and stock/cost-related fields.
- [ ] 4.4 Add UI/list/detail tests verifying effective-date fallback and audit-history visibility for purchases and sales.
- [ ] 4.5 Run focused reporting-date tests and the appropriate SQLite/PHP test suite, resolving regressions without expanding report date semantics.
