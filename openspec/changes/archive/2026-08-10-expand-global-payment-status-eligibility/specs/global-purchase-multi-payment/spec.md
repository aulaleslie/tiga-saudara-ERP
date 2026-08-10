## MODIFIED Requirements

### Requirement: Payment-focused global purchase list
The system SHALL provide `Pembayaran Pembelian Global` using the same columns, search, sorting, pagination, status presentation, payment presentation, and general table layout as the operational `Semua Pembelian` list while querying eligible purchases across all settings.

#### Scenario: List is not scoped to the active setting
- **WHEN** an authorized user opens `Pembayaran Pembelian Global` while any setting is active in the session
- **THEN** the list can contain eligible purchases from every `setting_id`
- **AND** changing the active session setting does not restrict the list to that setting

#### Scenario: Eligible paid and payable purchases are listed
- **WHEN** the global payment list is queried without a selected summary card
- **THEN** it contains non-archived purchases whose exact status is `RECEIVED PARTIALLY`, `RECEIVED`, or `RETURNED PARTIALLY`
- **AND** both purchases with a positive current live outstanding balance and fully paid purchases with a live outstanding balance less than or equal to zero are listed
- **AND** purchases in `APPROVED`, drafted, waiting-approval, rejected, fully `RETURNED`, or archived states are excluded

#### Scenario: Summary card filters refine the selected list
- **WHEN** a user selects the outstanding, overdue, or paid summary card while other global list filters are active
- **THEN** the table retains the active business, document-date, and text-search filters
- **AND** the outstanding card lists only eligible purchases with a positive current live outstanding balance
- **AND** the overdue card additionally lists only eligible purchases whose due date is before today
- **AND** the paid card lists only eligible fully paid purchases that have an active payment within the displayed recent 30-day period

### Requirement: Sample-inspired supplier multi-payment interface
The system SHALL present a supplier payment form using the existing ERP Bootstrap/CoreUI conventions and supported purchase-payment fields.

#### Scenario: Eligible supplier purchases are displayed as allocation rows
- **WHEN** the multi-payment form loads
- **THEN** it lists non-archived purchases with the starting purchase's exact `supplier_id`, a status of `RECEIVED PARTIALLY`, `RECEIVED`, or `RETURNED PARTIALLY`, and a positive current outstanding balance
- **AND** the candidate query does not apply a `setting_id` restriction
- **AND** each row displays transaction number, description, due date, total, outstanding balance, and an editable payment amount

#### Scenario: Ineligible starting purchase is rejected
- **WHEN** the requested starting purchase is archived, has a status other than `RECEIVED PARTIALLY`, `RECEIVED`, or `RETURNED PARTIALLY`, or has no positive current outstanding balance
- **THEN** the system does not render a payable allocation form
- **AND** no purchase payment is created

### Requirement: Multi-purchase allocation validation
The system MUST validate the submitted allocation against current server-side purchase and active-payment data rather than trusting rendered balances or client-provided purchase metadata.

#### Scenario: Tampered or newly ineligible candidate is rejected
- **WHEN** a submitted purchase has another `supplier_id`, has a status other than `RECEIVED PARTIALLY`, `RECEIVED`, or `RETURNED PARTIALLY`, is archived, is not an allowed candidate, or becomes fully paid before submission
- **THEN** the complete submission is rejected
- **AND** no payment is applied to any selected purchase

#### Scenario: Fully returned purchase is rejected even with a legacy positive balance
- **WHEN** a submitted purchase has exact status `RETURNED`
- **THEN** the complete submission is rejected regardless of its stored or live balance
- **AND** no purchase payment is created
