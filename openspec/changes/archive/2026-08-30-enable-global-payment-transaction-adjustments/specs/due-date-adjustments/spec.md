## MODIFIED Requirements

### Requirement: Due-date adjustments are independently permission controlled
The system SHALL allow a Super Admin to replace a purchase or sale due date through the existing application-wide authorization bypass. For every non–Super Admin user, the system SHALL allow a due-date adjustment only when the document is in an eligible post-approval status, the user has `purchases.due-date.override` for a purchase or `sales.due-date.override` for a sale, and either the document belongs to the active setting or the request uses the applicable dedicated Global Payment route with `purchasePayments.global.access` or `salePayments.global.access`.

Eligible purchase statuses SHALL be `APPROVED`, `RECEIVED PARTIALLY`, `RECEIVED`, `RETURNED PARTIALLY`, and `RETURNED`. Eligible sale statuses SHALL be `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, `RETURNED PARTIALLY`, and `RETURNED`.

#### Scenario: Permitted user adjusts a purchase due date
- **WHEN** a non–Super Admin user with `purchases.due-date.override` submits a valid due-date adjustment for an eligible purchase in the active setting
- **THEN** the system SHALL authorize the due-date adjustment

#### Scenario: Permitted user adjusts a sale due date
- **WHEN** a non–Super Admin user with `sales.due-date.override` submits a valid due-date adjustment for an eligible sale in the active setting
- **THEN** the system SHALL authorize the due-date adjustment

#### Scenario: Permitted global user adjusts a cross-setting due date
- **WHEN** a non–Super Admin user has the applicable due-date override and Global Payment access permissions and submits the dedicated global adjustment route for an eligible document outside the active setting
- **THEN** the system SHALL authorize the due-date replacement using the document's actual setting

#### Scenario: Reporting-date permission does not grant due-date access
- **WHEN** a non–Super Admin user has the applicable reporting-date permission but lacks the applicable due-date permission
- **THEN** the system SHALL deny a requested due-date change at the backend
- **AND** the system SHALL preserve the document and due-date audit history

#### Scenario: Unauthorized cross-setting or ineligible request is denied
- **WHEN** a non–Super Admin user targets a document outside the active setting through a normal route, lacks the applicable Global Payment access on a global route, or targets a drafted, waiting-for-approval, or rejected document
- **THEN** the system SHALL deny the due-date adjustment
- **AND** the system SHALL not create a due-date audit entry

#### Scenario: Super Admin uses the global bypass
- **WHEN** a Super Admin submits a due-date adjustment without the dedicated due-date permission
- **THEN** the system SHALL authorize the action through the existing application-wide Super Admin bypass

### Requirement: Document detail provides one independently authorized date-adjustment process
The normal and Global Payment purchase and sale detail pages SHALL provide one date-adjustment process containing only the reporting-date and due-date controls the current user is authorized to use. A reason SHALL apply to every field changed in one submission. A user authorized for only one field SHALL be able to change that field without receiving permission to change the other. Global Payment detail SHALL additionally require the applicable Global Payment access permission and SHALL submit through a dedicated global endpoint.

#### Scenario: Due-date-only user sees only the due-date control
- **WHEN** a user can adjust the document due date but cannot override its reporting date
- **THEN** the date-adjustment process SHALL expose the due-date control and hide or disable the reporting-date control

#### Scenario: User with both permissions submits both dates
- **WHEN** a user authorized for both fields submits effective reporting-date and due-date changes with one reason
- **THEN** the system SHALL process both requested changes as one operation

#### Scenario: Global detail composes access and field permissions
- **WHEN** a user opens an eligible document through Global Payment detail
- **THEN** the date-adjustment process SHALL appear only when the user has the applicable Global Payment access and at least one field-specific override permission
- **AND** a direct global submission for an unauthorized field SHALL be rejected without changing either date or audit history
