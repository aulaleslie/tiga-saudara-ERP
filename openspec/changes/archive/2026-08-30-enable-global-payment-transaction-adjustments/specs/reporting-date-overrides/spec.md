## MODIFIED Requirements

### Requirement: Reporting-date overrides are restricted for ordinary users and unrestricted for Super Admin
The system SHALL allow a Super Admin to create, replace, or clear a reporting-date override without permission, tenant, or lifecycle gates, preserving the application's existing global Super Admin authorization bypass. For every non–Super Admin user, the system SHALL allow a reporting-date override only when the document is in an eligible post-approval status, the user has `purchases.reporting-date.override` for a purchase or `sales.reporting-date.override` for a sale, and either the document belongs to the active setting or the request uses the applicable dedicated Global Payment route with `purchasePayments.global.access` or `salePayments.global.access`.

Eligible purchase statuses SHALL be `APPROVED`, `RECEIVED PARTIALLY`, `RECEIVED`, `RETURNED PARTIALLY`, and `RETURNED`. Eligible sale statuses SHALL be `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, `RETURNED PARTIALLY`, and `RETURNED`.

#### Scenario: Authorized user changes an approved purchase date
- **WHEN** a user with `purchases.reporting-date.override` changes the reporting date of an `APPROVED` purchase in the active setting
- **THEN** the system SHALL accept the date-only change when the request is otherwise valid

#### Scenario: Authorized user changes a dispatched sale date
- **WHEN** a user with `sales.reporting-date.override` changes the reporting date of a dispatched sale in the active setting
- **THEN** the system SHALL accept the date-only change when the request is otherwise valid

#### Scenario: Authorized user changes a cross-setting date from Global Payment
- **WHEN** a non–Super Admin user has the applicable reporting-date override and Global Payment access permissions and submits the dedicated global adjustment route for an eligible document outside the active setting
- **THEN** the system SHALL authorize the reporting-date operation using the document's actual setting

#### Scenario: Unauthorized ordinary-user or cross-setting request is rejected
- **WHEN** a non–Super Admin user lacks either required permission, or targets a document outside the active setting through a normal route, or targets an ineligible document
- **THEN** the system SHALL deny the request at the backend
- **AND** the system SHALL not change the document or create an audit entry

#### Scenario: Super Admin bypass is unrestricted
- **WHEN** a Super Admin creates, replaces, or clears an override for any purchase or sale without the dedicated reporting-date permission
- **THEN** the system SHALL authorize the action through the existing application-wide Super Admin bypass

#### Scenario: Ordinary user is blocked before approval
- **WHEN** a non–Super Admin user attempts to change the reporting date of a drafted, waiting-for-approval, or rejected purchase or sale
- **THEN** the system SHALL reject the request
- **AND** the system SHALL preserve the document and audit history
