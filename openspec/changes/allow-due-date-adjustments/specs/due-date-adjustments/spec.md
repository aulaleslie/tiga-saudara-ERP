## ADDED Requirements

### Requirement: Due-date adjustments are independently permission controlled
The system SHALL allow a Super Admin to replace a purchase or sale due date through the existing application-wide authorization bypass. For every non–Super Admin user, the system SHALL allow a due-date adjustment only when the document belongs to the active setting, is in an eligible post-approval status, and the user has `purchases.due-date.override` for a purchase or `sales.due-date.override` for a sale.

Eligible purchase statuses SHALL be `APPROVED`, `RECEIVED PARTIALLY`, `RECEIVED`, `RETURNED PARTIALLY`, and `RETURNED`. Eligible sale statuses SHALL be `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, `RETURNED PARTIALLY`, and `RETURNED`.

#### Scenario: Permitted user adjusts a purchase due date
- **WHEN** a non–Super Admin user with `purchases.due-date.override` submits a valid due-date adjustment for an eligible purchase in the active setting
- **THEN** the system SHALL authorize the due-date adjustment

#### Scenario: Permitted user adjusts a sale due date
- **WHEN** a non–Super Admin user with `sales.due-date.override` submits a valid due-date adjustment for an eligible sale in the active setting
- **THEN** the system SHALL authorize the due-date adjustment

#### Scenario: Reporting-date permission does not grant due-date access
- **WHEN** a non–Super Admin user has the applicable reporting-date permission but lacks the applicable due-date permission
- **THEN** the system SHALL deny a requested due-date change at the backend
- **AND** the system SHALL preserve the document and due-date audit history

#### Scenario: Cross-setting or ineligible request is denied
- **WHEN** a non–Super Admin user targets a document outside the active setting or in a drafted, waiting-for-approval, or rejected status
- **THEN** the system SHALL deny the due-date adjustment
- **AND** the system SHALL not create a due-date audit entry

#### Scenario: Super Admin uses the global bypass
- **WHEN** a Super Admin submits a due-date adjustment without the dedicated due-date permission
- **THEN** the system SHALL authorize the action through the existing application-wide Super Admin bypass

### Requirement: Authorized users can replace a due date without chronological restrictions
An authorized user SHALL be able to replace the authoritative `due_date` of an eligible purchase or sale repeatedly. Every request SHALL provide a non-empty reason and a valid, non-null calendar date. The system SHALL NOT impose chronological restrictions relative to the transaction date, reporting date, current date, prior due date, or existing due date.

#### Scenario: Due date is shortened to before the transaction date
- **WHEN** an authorized user submits a valid due date before the document transaction date with a non-empty reason
- **THEN** the system SHALL save the selected date as the document's authoritative due date

#### Scenario: Due date is extended into the future
- **WHEN** an authorized user submits a valid due date later than the existing due date with a non-empty reason
- **THEN** the system SHALL save the selected date as the document's authoritative due date

#### Scenario: Due date is shortened but remains after the transaction date
- **WHEN** an authorized user submits a valid due date earlier than the existing due date with a non-empty reason
- **THEN** the system SHALL save the selected date as the document's authoritative due date

#### Scenario: Missing date or reason is rejected
- **WHEN** an authorized user omits the due date, submits a non-date value, or omits a non-empty reason
- **THEN** the system SHALL reject the request
- **AND** the system SHALL preserve the current due date and audit history

#### Scenario: Unchanged due date is a no-op
- **WHEN** an authorized user submits the document's current due date without another effective date change
- **THEN** the system SHALL not append a due-date audit entry
- **AND** the system SHALL report that no effective change was requested

### Requirement: Every effective due-date adjustment has immutable audit history
The system SHALL atomically persist every effective due-date replacement with an append-only audit entry containing the document type and identity, setting, actor, timestamp, non-empty reason, prior due date, and resulting due date. Prior audit entries SHALL NOT be updated or deleted by later adjustments.

#### Scenario: Successful adjustment records old and new values
- **WHEN** an authorized user changes a purchase or sale due date
- **THEN** the document update and audit insert SHALL commit together
- **AND** the audit entry SHALL identify the actor, reason, previous due date, and resulting due date

#### Scenario: Audit persistence failure rolls back the due date
- **WHEN** the system cannot persist the due-date audit entry
- **THEN** the system SHALL roll back the due-date update
- **AND** the document SHALL retain its prior due date

#### Scenario: Repeated adjustments retain complete history
- **WHEN** an authorized user changes the same due date more than once
- **THEN** the system SHALL append one audit entry for each effective change
- **AND** all earlier entries SHALL remain unchanged

### Requirement: Due-date changes preserve unrelated document facts
A due-date adjustment SHALL change only the authoritative `due_date` and its audit history. It SHALL preserve the original transaction `date`, active `reporting_date`, `payment_term_id`, reference number, amounts, payment records and statuses, lifecycle status, receiving or dispatch history, returns, stock movements, serial records, inventory valuation, tax, and cost history.

#### Scenario: Negotiated exception preserves the payment-term reference
- **WHEN** an authorized user replaces a due date that was originally calculated from a payment term
- **THEN** the system SHALL preserve the document's `payment_term_id`
- **AND** the audit reason and old/new dates SHALL document the negotiated exception

#### Scenario: Due-date change has no inventory or payment mutation
- **WHEN** a purchase or sale due date is changed successfully
- **THEN** the system SHALL not create, update, invalidate, or delete any payment, receipt, dispatch, return, stock, serial, valuation, tax, or cost record

### Requirement: Existing due-date consumers use the replacement value
After a successful adjustment, every existing screen, filter, overdue indicator, payment view, print view, report, and export that reads the authoritative purchase or sale `due_date` SHALL use the replacement value. A report or aging surface whose defined basis does not read `due_date` SHALL retain its existing date semantics.

#### Scenario: Purchase maturity surfaces use the replacement
- **WHEN** a purchase due date is replaced
- **THEN** purchase overdue summaries, due-date filters, payment views, print output, the Primary Purchase Report due-date basis, Supplier Payables, and due-date-based Aged Payables SHALL use the replacement value

#### Scenario: Sales maturity surfaces use the replacement
- **WHEN** a sale due date is replaced
- **THEN** sales overdue summaries, due-date filters, payment views, and Customer Receivables due-date filtering, display, and export SHALL use the replacement value

#### Scenario: Non-due-date aging basis remains unchanged
- **WHEN** a due date is replaced for a document shown in a report whose defined aging basis uses the transaction date or another event date
- **THEN** that report SHALL continue to use its defined non-due-date basis

### Requirement: Document detail provides one independently authorized date-adjustment process
The purchase and sale detail pages SHALL provide one date-adjustment process containing only the reporting-date and due-date controls the current user is authorized to use. A reason SHALL apply to every field changed in one submission. A user authorized for only one field SHALL be able to change that field without receiving permission to change the other.

#### Scenario: Due-date-only user sees only the due-date control
- **WHEN** a user can adjust the document due date but cannot override its reporting date
- **THEN** the date-adjustment process SHALL expose the due-date control and hide or disable the reporting-date control

#### Scenario: User with both permissions submits both dates
- **WHEN** a user authorized for both fields submits effective reporting-date and due-date changes with one reason
- **THEN** the system SHALL process both requested changes as one operation

