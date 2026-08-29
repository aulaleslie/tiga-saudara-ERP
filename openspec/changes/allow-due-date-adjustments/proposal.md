## Why

Supplier and customer payment terms can be renegotiated after a purchase or sale has passed approval, but the ERP currently offers no narrow, auditable way to revise the authoritative due date without reopening broader document editing. Authorized users need to extend or shorten due dates while preserving document, payment, stock, and lifecycle integrity.

## What Changes

- Add dedicated purchase and sales permissions for post-approval due-date adjustments.
- Allow an authorized user to replace a purchase or sale due date with any valid calendar date, including a date before the transaction date, with no chronological restriction relative to the document, reporting, current, prior, or existing due date.
- Require a non-empty reason and append immutable actor-attributed old/new due-date audit history for every effective change.
- Present reporting-date and due-date adjustment fields in the same document-detail process while authorizing each field independently.
- Apply a requested reporting-date and due-date change atomically when both are submitted together.
- Make the revised authoritative `due_date` immediately visible to all existing screens, filters, exports, overdue indicators, and reports that already consume that field, without changing surfaces that use another aging basis.
- Preserve the original transaction date, reporting-date override semantics, payment-term reference, amounts, payments, stock, receipt/dispatch history, reference numbers, and lifecycle status.

## Capabilities

### New Capabilities

- `due-date-adjustments`: Permission-controlled, tenant-scoped purchase and sales due-date replacement with unrestricted calendar-date selection and immutable audit history.

### Modified Capabilities

- `reporting-date-overrides`: Allow reporting-date changes to share a date-adjustment submission with independently authorized due-date changes and require the combined update to be atomic.

## Impact

- Adds permissions under the existing Purchase and Sales permission groups.
- Affects purchase and sale policies, detail-page date-adjustment UI, protected routes/controllers, transactional services, audit models/relationships, and additive database migrations.
- Changes the authoritative `purchases.due_date` or `sales.due_date`; existing due-date consumers will therefore reflect the replacement automatically.
- Requires focused authorization, validation, audit, atomicity, UI, and report-boundary regression tests for both modules, including Super Admin and cross-setting behavior.
- No external dependency, destructive migration, historical backfill, or API compatibility break is expected.
