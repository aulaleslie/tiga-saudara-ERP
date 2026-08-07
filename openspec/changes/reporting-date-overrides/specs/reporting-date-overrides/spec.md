## ADDED Requirements

### Requirement: Reporting-date overrides are restricted to authorized post-approval documents
The system SHALL allow a reporting-date override only when the current user has `purchases.reporting-date.override` for a purchase or `sales.reporting-date.override` for a sale, the document belongs to the active setting, and the document is in an eligible post-approval status.

Eligible purchase statuses SHALL be `APPROVED`, `RECEIVED PARTIALLY`, `RECEIVED`, `RETURNED PARTIALLY`, and `RETURNED`. Eligible sale statuses SHALL be `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, `RETURNED PARTIALLY`, and `RETURNED`.

#### Scenario: Authorized user changes an approved purchase date
- **WHEN** a user with `purchases.reporting-date.override` changes the reporting date of an `APPROVED` purchase in the active setting
- **THEN** the system SHALL accept the date-only change when the request is otherwise valid

#### Scenario: Authorized user changes a dispatched sale date
- **WHEN** a user with `sales.reporting-date.override` changes the reporting date of a dispatched sale in the active setting
- **THEN** the system SHALL accept the date-only change when the request is otherwise valid

#### Scenario: Unauthorized or cross-setting request is rejected
- **WHEN** a user without the required permission, or a user targeting a document outside the active setting, attempts to create, replace, or clear an override
- **THEN** the system SHALL deny the request at the backend
- **AND** the system SHALL not change the document or create an audit entry

#### Scenario: Pre-approval document is rejected
- **WHEN** a user attempts to change the reporting date of a drafted, waiting-for-approval, or rejected purchase or sale
- **THEN** the system SHALL reject the request
- **AND** the system SHALL preserve the document and audit history

### Requirement: Authorized users can create, replace, and clear reporting-date overrides
The system SHALL store a nullable reporting-date override separately from the original purchase or sale date. An authorized user SHALL be able to set, replace, or clear the override repeatedly, and each operation SHALL require a non-empty reason.

The system SHALL accept any valid calendar date, including past and future dates. Clearing the override SHALL restore the effective date to the original document date.

#### Scenario: User assigns a past or future reporting date
- **WHEN** an authorized user submits any valid past, present, or future calendar date with a non-empty reason
- **THEN** the system SHALL save that date as the document's reporting-date override

#### Scenario: User replaces an existing override
- **WHEN** an authorized user submits a new valid reporting date for a document that already has an override
- **THEN** the system SHALL replace the current override with the new date
- **AND** the system SHALL retain the history of both changes

#### Scenario: User clears an existing override
- **WHEN** an authorized user clears a reporting-date override with a non-empty reason
- **THEN** the system SHALL set the override to null
- **AND** the effective date SHALL resolve to the original document date

#### Scenario: Missing reason is rejected
- **WHEN** an authorized user submits a create, replacement, or clear action without a non-empty reason
- **THEN** the system SHALL reject the action
- **AND** the system SHALL preserve the prior override and audit history

### Requirement: Reporting-date changes preserve operational and financial facts
The system SHALL not alter the original document `date`, `due_date`, reference number, lifecycle status, payment data, receiving/dispatch data, stock movements, inventory valuation, or cost history when creating, replacing, or clearing a reporting-date override.

The date-only action SHALL not require `due_date` to be on or after the reporting date.

#### Scenario: Override is later than supplier due date
- **WHEN** an authorized user sets a purchase reporting date later than its existing supplier due date
- **THEN** the system SHALL save the override
- **AND** the system SHALL leave the due date unchanged

#### Scenario: Override does not alter original document facts
- **WHEN** an authorized user successfully changes a purchase or sale reporting-date override
- **THEN** the original document date and all unrelated operational and financial fields SHALL remain unchanged

### Requirement: Every reporting-date operation has immutable audit history
The system SHALL atomically record an immutable audit entry for every successful create, replacement, or clear operation. Each entry SHALL include the document identity and setting, actor, timestamp, non-empty reason, original document date, prior override value, and resulting override value.

#### Scenario: Successful override produces audit data
- **WHEN** an authorized user successfully changes a reporting-date override
- **THEN** the system SHALL persist the document update and its audit entry together
- **AND** the audit entry SHALL identify the actor, reason, original date, previous override, and resulting override

#### Scenario: Clear operation remains auditable
- **WHEN** an authorized user clears an existing override
- **THEN** the system SHALL append an audit entry whose resulting override is null
- **AND** prior audit entries SHALL remain unchanged

### Requirement: Operational document views show the effective reporting date
The purchase and sale operational list and detail views SHALL display the effective document date, defined as `reporting_date` when present and the original document `date` otherwise. The original date and reporting-date change history SHALL remain available in the document's audit/history view.

This requirement SHALL not change report queries, filters, sorting, exports, payables/receivables, stock, inventory valuation, or general-ledger behavior.

#### Scenario: List displays override as the document date
- **WHEN** a purchase or sale with a reporting-date override appears on its operational list
- **THEN** the displayed document date SHALL be the reporting-date override

#### Scenario: Detail displays original date when no override exists
- **WHEN** a purchase or sale without a reporting-date override is viewed
- **THEN** the displayed document date SHALL be the original document date

#### Scenario: Original date remains visible in history
- **WHEN** a user views a document with reporting-date audit entries
- **THEN** the audit/history view SHALL display the original document date and each recorded override change
