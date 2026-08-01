## MODIFIED Requirements

### Requirement: Payment-focused global sales list
The system SHALL provide a sales-payment list using the established sales table behavior while querying eligible sales across all settings.

#### Scenario: List is independent of the active setting
- **WHEN** an authorized user opens `Pembayaran Penjualan Global`
- **THEN** eligible sales from every `setting_id` can be listed
- **AND** the active session setting does not restrict the results

#### Scenario: Approved-up paid and payable sales are eligible
- **WHEN** the global list is queried without a selected summary card
- **THEN** it contains non-archived sales whose exact status is `APPROVED`, `DISPATCHED PARTIALLY`, or `DISPATCHED`
- **AND** both sales with a positive current live outstanding balance and fully paid sales with a live outstanding balance less than or equal to zero are listed
- **AND** draft, waiting-approval, rejected, returned, and archived sales are excluded

#### Scenario: Business and document date filters compose with the list
- **WHEN** an authorized user selects a business and/or an inclusive document-date range
- **THEN** the list contains only eligible sales with the selected `setting_id` and whose sale `date` falls within the supplied boundaries
- **AND** each global row displays its sale's business context
- **AND** the active session setting remains irrelevant

#### Scenario: Search supports operational sales identifiers and stored descriptions
- **WHEN** an authorized user searches the global list
- **THEN** matching can use sale reference, imported sales reference, tax reference, customer name/contact, product name/code, sale note, tag, POS receipt number, POS transaction code, or persisted sales bundle-item name
- **AND** a search match does not bypass the selected business, date, lifecycle, archival, or summary-card filters

#### Scenario: Summary card filters refine the selected list
- **WHEN** a user selects the outstanding, overdue, or paid summary card while other global list filters are active
- **THEN** the table retains the active business, document-date, and text-search filters
- **AND** the outstanding card lists only sales with a positive current live outstanding balance
- **AND** the overdue card additionally lists only sales whose due date is before today
- **AND** the paid card lists only fully paid sales that have an active payment within the displayed recent 30-day period

#### Scenario: Global rows expose payment-only actions
- **WHEN** an authorized user views a global sales row with a positive current live outstanding balance
- **THEN** the available actions are limited to read-only detail, payment history, and allowed payment creation
- **AND** ordinary sale creation, editing, deletion, approval, dispatch, duplication, archive, and attachment-management actions are absent

#### Scenario: Fully paid global rows are read-only
- **WHEN** an authorized user views a fully paid global sales row
- **THEN** read-only detail and payment history remain available
- **AND** no create-payment action is displayed or accepted

### Requirement: Dedicated cross-setting read-only sale inspection
The system SHALL provide dedicated global detail and payment-history contexts that do not apply the active session setting restriction.

#### Scenario: User opens a sale belonging to another setting
- **WHEN** an authorized user opens a base-eligible paid or payable sale whose setting differs from the active session setting
- **THEN** the global detail displays the sale and related dispatch and payment information
- **AND** the page presents company information from the sale's actual setting

#### Scenario: Global detail is read-only except for payable payment creation
- **WHEN** an authorized user views a sale through the global detail route
- **THEN** sale update, approval, dispatch, archive, delete, and attachment-management controls are absent
- **AND** payment creation is available only when the sale has a positive current live outstanding balance and the user has `salePayments.create`
- **AND** a fully paid sale remains inspectable but cannot open or submit a new payment

#### Scenario: Normal sale routes remain setting-scoped
- **WHEN** a user requests an existing normal sale route for a sale outside the active setting
- **THEN** the normal ownership behavior remains in force
- **AND** global payment access is granted only through dedicated routes protected by `salePayments.global.access`
