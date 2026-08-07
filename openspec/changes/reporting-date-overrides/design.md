## Context

Purchase and sale documents use their persisted `date` for more than presentation: document-reference allocation, historical inventory/cost replay, and operational workflows all depend on it. Ordinary editing is already blocked once documents progress beyond approval, receipt, or dispatch. `due_date` is a separate contractual payment date and must remain stable when the business chooses a different date for the document's reporting identity.

The change needs a narrow, tenant-scoped privileged action for both modules. It must preserve the original record and produce a durable history of changes, while operational purchase and sale list/detail screens present the current effective date. Report queries and exports are intentionally deferred.

## Goals / Non-Goals

**Goals:**

- Allow users holding a dedicated purchase or sales permission to set, replace, or clear a reporting-date override on a post-approval document in their active setting.
- Treat `reporting_date ?? date` as the visible document date on purchase and sale lists and detail pages.
- Preserve `date`, `due_date`, references, payments, receiving/dispatch history, and inventory/cost data exactly as they are.
- Record each successful update, including a clear, in an immutable, actor-attributed audit history.

**Non-Goals:**

- Changing document dates, payment dates, due dates, receiving/dispatch dates, reference numbers, stock movements, costs, or lifecycle status.
- Reusing or expanding the received-purchase monetary-correction workflow.
- Changing report, export, aging, inventory, valuation, stock-mutation, or general-ledger date semantics in this change.
- Adding approval workflow to the override itself.

## Decisions

### Store an optional reporting date separately from the document date

Add nullable `reporting_date` columns to `purchases` and `sales`. A shared effective-date accessor/presentation helper will resolve `reporting_date ?? date` without mutating the original document date.

This permits a document's displayed operational identity to move while preserving every existing subsystem that consumes `date`. Replacing `date` was rejected because it would change stock-cost replay ordering and produce a mismatch between retained reference numbers and their creation period.

### Use a dedicated append-only audit record

Create a reporting-date audit store with the tenant, document type/id, actor, reason, original document date, prior override, resulting override (nullable for clear), and timestamps. Do not update or delete historical audit rows. The update and corresponding audit insert occur together in one database transaction while the target document row is locked.

Existing `purchase_corrections` is not reused: it applies only to received purchase monetary corrections and carries payment-reconciliation semantics that are inappropriate for sales or a date-only override.

### Gate ordinary users by a dedicated permission and lifecycle eligibility

Introduce `purchases.reporting-date.override` and `sales.reporting-date.override`. For non–Super Admin users, backend authorization must enforce the dedicated permission, active-setting ownership, and eligible lifecycle status; hiding an action in the UI is insufficient. The application-wide `Gate::before` Super Admin bypass remains unchanged and therefore authorizes Super Admins without an explicit reporting-date permission, tenant, or lifecycle gate.

Eligible purchases are `APPROVED`, `RECEIVED PARTIALLY`, `RECEIVED`, `RETURNED PARTIALLY`, or `RETURNED`. Eligible sales are `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, `RETURNED PARTIALLY`, or `RETURNED`. Payment status is not an independent condition because the existing workflow only permits payment after the relevant approval stage.

### Allow any valid calendar date and preserve due-date semantics

The action accepts a valid past, present, or future calendar date. A user can revise an existing override repeatedly or clear it. A non-empty reason is required for all three actions. This date-only endpoint deliberately does not apply the ordinary `due_date >= date` validation: the reporting date may be later than a fixed supplier/customer due date.

### Present the effective date as the document date, without changing reporting yet

Purchase and sale operational list and detail pages will label and display the effective date as the standard document date. Detail/audit history exposes the unmodified original date and all override changes. This keeps the current business identity legible without prematurely changing report filters, exports, or financial/inventory reporting.

## Risks / Trade-offs

- [A user mistakes the visible date for the original transaction date] → Detail history identifies the original date and every override with reason and actor.
- [An override is used to bypass approval or cross-tenant boundaries] → Policy/service checks enforce permission, eligible state, and active-setting ownership on every request.
- [A later reporting date appears earlier than due date validation expects] → The dedicated action never modifies or compares `due_date`.
- [Other screens begin using the override accidentally] → Only explicitly scoped purchase/sale operational list/detail presentation uses the effective-date helper; report work is deferred and covered by focused regression tests.
- [Concurrent edits lose an audit event] → Lock the document row and persist the override plus its audit row atomically.

## Migration Plan

1. Add nullable `reporting_date` columns to purchases and sales, then create the audit table and indexes using additive, backwards-compatible migrations.
2. Deploy with all existing rows having `NULL` overrides, so their visible date continues to resolve to the original `date`.
3. Register permissions through the established permission sync/seeder and deploy protected actions and UI presentation.
4. Rollback application code safely by leaving additive columns/audits in place; older code continues to use the original `date`. Do not delete audit data as part of rollback.

## Open Questions

- None for the initial capability. The specific report and export surfaces that should later consume the effective reporting date are deliberately deferred.
