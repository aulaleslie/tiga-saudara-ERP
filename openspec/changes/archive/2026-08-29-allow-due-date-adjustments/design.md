## Context

Purchases and sales already store an authoritative `due_date` that is populated during document creation and used directly by overdue cards, list filters, payment views, print output, payables/receivables reports, and selected aging calculations. Normal document editing becomes progressively restricted after approval, receipt, or dispatch, so renegotiated supplier or customer maturity dates cannot be safely changed without entering a broader edit workflow.

The existing reporting-date capability provides a useful interaction and security pattern: dedicated per-module permissions, model policies, protected detail-page actions, row locking, atomic audit insertion, and immutable history. Reporting dates differ semantically, however: `reporting_date` is a nullable override over an immutable transaction date, whereas a negotiated due-date adjustment must replace the authoritative `due_date` so every existing due-date consumer sees it immediately.

This is a cross-cutting Laravel change spanning Purchase, Sale, shared services, Spatie permissions, Blade/JavaScript detail views, Eloquent audit relationships, and additive migrations. It must preserve existing reporting-date endpoints and behavior while enabling both fields to be submitted together.

## Goals / Non-Goals

**Goals:**

- Provide independent purchase and sales permissions for post-approval due-date replacement.
- Accept any valid non-null calendar date without chronological comparison to any other date.
- Put reporting-date and due-date controls in one detail-page process while exposing and authorizing each field independently.
- Lock the target document and persist all requested field changes and audit entries in one transaction.
- Preserve immutable old/new due-date history with actor, tenant, reason, and timestamp.
- Update the existing authoritative `due_date` so current consumers adopt the new maturity date without query rewrites.
- Preserve the existing reporting-date override and audit contracts.

**Non-Goals:**

- Reopening general purchase or sale editing after approval, receipt, or dispatch.
- Recalculating or replacing `payment_term_id` when a negotiated due date is entered.
- Clearing a due date or introducing a nullable due-date override layer.
- Recalculating amounts, payments, payment status, stock, serials, receipt/dispatch data, returns, tax, valuation, or cost history.
- Changing an aging report that currently uses transaction date or another event date rather than `due_date`.
- Adding a separate approval workflow for date adjustments.

## Decisions

### Replace the authoritative due date rather than add an override column

The date-adjustment service will update `purchases.due_date` or `sales.due_date` directly. All current due-date consumers will therefore adopt the renegotiated maturity date automatically.

Adding `due_date_override` was rejected because it would require converting every due-date query, display, export, and overdue calculation to an effective-date expression and would leave a high risk of inconsistent maturity behavior. The original value remains recoverable through immutable audit history rather than a second active column.

### Use independent permissions and policy abilities

Add `purchases.due-date.override` and `sales.due-date.override` under their existing permission groups, with `overrideDueDate` abilities on `PurchasePolicy` and `SalePolicy`. Ordinary users must satisfy the applicable permission, active-setting boundary, and the same eligible post-approval status set used by reporting-date overrides. The application-wide Super Admin `Gate::before` bypass remains authoritative.

The reporting-date permissions do not imply due-date permission and vice versa. This prevents a user assigned for financial-period normalization from silently receiving contractual maturity authority.

### Add a shared atomic date-adjustment service and explicit action payload

Add a shared service that accepts a Purchase or Sale, actor, reason, and explicit actions for each field. The request contract distinguishes omission from action, for example:

- reporting date action: `keep`, `set`, or `clear`;
- due date action: `keep` or `set`;
- corresponding date values when an action is `set`;
- one required reason when at least one effective action is requested.

Module-specific controllers or form requests translate the HTTP payload into this command. Existing reporting-date routes remain available for compatibility and delegate to the same transactional core where practical.

Inside one database transaction, the service reloads the document with `lockForUpdate`, authorizes each requested action against the locked model, validates that at least one value changes, updates the requested fields together, and appends the applicable audit entries. Reauthorizing after the lock closes the current time-of-check/time-of-use gap if lifecycle or tenant state changes concurrently.

### Preserve the reporting audit and add a dedicated due-date audit

Keep `reporting_date_audits` and its existing model contract unchanged. Add an additive polymorphic `due_date_audits` table containing document type/id, setting, actor, reason, `prior_due_date`, `resulting_due_date`, and timestamps, with indexes consistent with current audit lookup patterns.

A combined submission inserts one reporting audit and one due-date audit in the same transaction. Separate audit stores were chosen over repurposing `reporting_date_audits`, because existing rows are explicitly reporting-date history and its `original_date`/override columns do not accurately describe due-date replacement.

### Treat any valid calendar date as acceptable

The due date is required for a set action and must parse as a calendar date, but it is not compared with the transaction date, reporting date, current date, existing due date, or prior audit values. This deliberately supports supplier or customer renegotiation that either extends or shortens the term, including selection before the transaction date.

Submitting the unchanged current due date alone is a no-op and creates no audit row. Clearing is unsupported because operational code assumes a maturity date even though historical schema nullability differs between modules.

### Build one permission-aware detail interaction

Replace the reporting-only modal presentation with a document-date adjustment modal on Purchase and Sale detail pages. Render reporting controls only when `overrideReportingDate` is authorized and due-date controls only when `overrideDueDate` is authorized. Users with either permission can open the process; users with both can submit both fields with one reason.

The existing reporting clear behavior remains available within this interaction. Audit history can be presented as clearly labeled reporting-date and due-date entries while retaining the underlying separate relationships. Server authorization remains mandatory regardless of hidden or disabled controls.

### Preserve existing report boundaries

No query will be rewritten merely because this capability is introduced. Consumers that already read `due_date`—including overdue cards, due-date list filters, Supplier Payables, Customer Receivables, the Primary Purchase Report due-date basis, and due-date-based Aged Payables—will see the replacement naturally. Aged Receivables currently ages from `sales.date`; that behavior remains unchanged because changing its basis is a separate reporting requirement.

This means rerunning a due-date-based historical or as-of report after an adjustment can produce a different maturity classification. That is an intentional consequence of replacing the authoritative negotiated due date; the immutable audit trail supplies the historical explanation.

## Risks / Trade-offs

- [A backdated due date makes a document immediately or retroactively overdue] → Accept this as requested business behavior and require dedicated permission plus an audit reason.
- [A future due date relaxes an already overdue balance] → Make old/new values and actor visible in immutable history and cover overdue transitions with regression tests.
- [A user authorized for one date field tampers with the other request field] → Use explicit per-field actions and backend authorization against the locked document.
- [One half of a combined adjustment persists] → Perform field updates and all audit inserts in one database transaction and test forced-failure rollback.
- [Concurrent lifecycle or date changes bypass stale authorization or lose an audit event] → Lock and reload the document, then authorize and compare values inside the transaction.
- [The stored payment term no longer mathematically matches the due date] → Preserve it as the originally selected term and record the negotiated exception in due-date audit history.
- [Historical report results change after due-date replacement] → Treat the current due date as authoritative and rely on audit history; do not silently introduce effective-date snapshots.
- [Due-date and reporting-date history become visually confusing] → Label field type and old/new values explicitly in the shared history presentation.

## Migration Plan

1. Add the append-only `due_date_audits` table and indexes without changing existing purchase, sale, or reporting-audit rows.
2. Register and synchronize the two new permissions; existing roles receive no new due-date authority by default.
3. Deploy policies, request/controller handling, the shared transactional service, model relationships, and permission-aware detail UI.
4. Run focused Purchase and Sale authorization, audit, atomicity, UI, and due-date-consumer regression tests, followed by the project SQLite verification command.
5. Roll back application behavior by removing the new routes/UI/permissions while leaving audit data in place. The authoritative due dates already changed by authorized users must not be destructively restored automatically; any reversal must be another audited adjustment.

## Open Questions

None. The agreed behavior permits both shortening and relaxation to any valid calendar date for Purchases and Sales, including dates before the transaction date, while preserving existing report-specific date bases.
