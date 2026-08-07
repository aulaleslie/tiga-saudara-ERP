## 1. Persistence and permissions

- [x] 1.1 Add additive nullable `reporting_date` migrations for purchases and sales, plus the indexed append-only reporting-date audit table.
- [x] 1.2 Add reporting-date audit and effective-date model relationships/accessors for Purchase and Sale without changing their original `date` behavior.
- [x] 1.3 Register `purchases.reporting-date.override` and `sales.reporting-date.override` through the existing permission configuration and sync workflow.

## 2. Authorization and date-only update workflow

- [x] 2.1 Implement tenant-scoped authorization/policy checks and approved-or-later purchase/sale statuses for ordinary users, while preserving the existing unrestricted global Super Admin bypass.
- [x] 2.2 Implement an atomic reporting-date override service that locks the document, validates a valid date or explicit clear plus required reason, saves only the override, and appends its audit entry.
- [x] 2.3 Add protected purchase and sale endpoints/actions and standard UI feedback for setting, replacing, and clearing overrides.
- [x] 2.4 Ensure the workflow accepts past, present, and future dates and never recalculates or validates `due_date` against the override.

## 3. Operational document presentation

- [x] 3.1 Show the effective date (`reporting_date ?? date`) as the normal date on purchase operational list and detail views.
- [x] 3.2 Show the effective date (`reporting_date ?? date`) as the normal date on sale operational list and detail views.
- [x] 3.3 Add readable document audit/history presentation for original date, prior/resulting overrides, reason, actor, and timestamp.
- [x] 3.4 Keep report queries, report exports, aging, inventory, stock mutation, valuation, and general-ledger presentation on their existing date sources.

## 4. Verification

- [x] 4.1 Add purchase feature tests for lifecycle and setting authorization, create/replace/clear flows, required reason, valid past/future dates, and immutable audit data.
- [x] 4.2 Add sales feature tests for lifecycle and setting authorization, create/replace/clear flows, required reason, valid past/future dates, and immutable audit data.
- [x] 4.3 Add regression tests proving date-only overrides preserve original date, due date, reference, payments, statuses, receiving/dispatch data, and stock/cost-related fields.
- [x] 4.4 Add UI/list/detail tests verifying effective-date fallback and audit-history visibility for purchases and sales.
- [x] 4.5 Run focused reporting-date tests and the appropriate SQLite/PHP test suite, resolving regressions without expanding report date semantics.

### Verification record (2026-08-07)

Focused suites, each run as `bash scripts/test-with-fresh-sqlite.sh <path>`. The
`composer test:fresh-sqlite -- <path>` form forwards arguments correctly but
aborts at composer's 300s process timeout, so the wrapper script was invoked
directly with the same environment.

| Suite | Result |
| --- | --- |
| `Modules/Purchase/Tests/Feature/PurchaseReportingDateUITest.php` | 11 passed (33 assertions) |
| `Modules/Sale/Tests/Feature/SaleReportingDateUITest.php` | 11 passed (33 assertions) |
| `Modules/Purchase/Tests/Feature/PurchaseReportingDateAuthorizationTest.php` | 9 passed (10 assertions) |
| `Modules/Purchase/Tests/Feature/ReportingDateOverrideTest.php` | 7 passed (15 assertions) |
| `Modules/Sale/Tests/Feature/SaleReportingDateAuthorizationTest.php` | 9 passed (10 assertions) |

`git diff --check` reports no whitespace errors.

Eager-loading verification asserts the relation state handed to the view
(`relationLoaded('reportingDateAudits')` plus a loaded `actor` on every audit)
and counts queries during the detail request, rather than relying on rendered
actor names. Confirmed non-vacuous: temporarily removing the controller's
`load(['reportingDateAudits.actor'])` makes both tests fail.

Defects found and fixed while making these tests pass:

- `ReportingDateAudit` inherited `BaseModel`'s uppercasing, which mangled
  `auditable_type` so the `reportingDateAudits` morph relation never matched —
  the audit trail was silently empty. Opted the entity out of uppercasing.
- `Sale::reportingDateAudits()` declared a `HasMany` return type while returning
  `MorphMany`, throwing a `TypeError` (HTTP 500) on every sale detail page.
- Both reporting-date controllers called `$this->authorize()` without the
  `AuthorizesRequests` trait, so every override endpoint returned 500 instead of
  enforcing authorization.

Behavior deliberately left unchanged: `AuthServiceProvider`'s application-wide
`Gate::before` grants Super Admin every ability, so the role bypasses the
reporting-date policy without the explicit permission. That bypass predates this
change, so the test now asserts the actual behavior instead of altering it.
