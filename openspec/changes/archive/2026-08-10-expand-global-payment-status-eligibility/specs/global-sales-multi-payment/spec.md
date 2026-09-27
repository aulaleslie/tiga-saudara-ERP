## MODIFIED Requirements

### Requirement: Payment-focused global sales list
The system SHALL provide a sales-payment list using the established sales table behavior while querying eligible sales across all settings.

#### Scenario: List is independent of the active setting
- **WHEN** an authorized user opens `Pembayaran Penjualan Global`
- **THEN** eligible sales from every `setting_id` can be listed
- **AND** the active session setting does not restrict the results

#### Scenario: Eligible paid and payable sales are listed
- **WHEN** the global list is queried without a selected summary card
- **THEN** it contains non-archived sales whose exact status is `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, or `RETURNED PARTIALLY`
- **AND** both sales with a positive current live outstanding balance and fully paid sales with a live outstanding balance less than or equal to zero are listed
- **AND** draft, waiting-approval, rejected, fully `RETURNED`, and archived sales are excluded

#### Scenario: Summary card filters refine the selected list
- **WHEN** a user selects the outstanding, overdue, or paid summary card while other global list filters are active
- **THEN** the table retains the active business, document-date, and text-search filters
- **AND** the outstanding card lists only eligible sales with a positive current live outstanding balance
- **AND** the overdue card additionally lists only eligible sales whose due date is before today
- **AND** the paid card lists only eligible fully paid sales that have an active payment within the displayed recent 30-day period

### Requirement: Server-authoritative allocation validation
The system MUST validate submitted allocations against current locked sale and active-payment data rather than trusting rendered balances or client metadata.

#### Scenario: Customer mismatch or changed eligibility rejects the batch
- **WHEN** a submitted sale belongs to another customer, is archived, has a status other than `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, or `RETURNED PARTIALLY`, or becomes fully paid
- **THEN** the complete submission is rejected
- **AND** no selected sale is partially settled

#### Scenario: Fully returned sale is rejected even with a legacy positive balance
- **WHEN** a submitted sale has exact status `RETURNED`
- **THEN** the complete submission is rejected regardless of its stored or live balance
- **AND** no sale payment is created
