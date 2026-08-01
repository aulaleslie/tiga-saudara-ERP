## ADDED Requirements

### Requirement: Explicit global sales payment filter application
The system SHALL present the global sales-payment business and document-date controls as a single filter panel with explicit application, reset, and applied-state feedback.

#### Scenario: User applies a sales document-date filter
- **WHEN** an authorized user enters a business and/or one or both `Tanggal Dokumen` boundaries and selects `Terapkan Filter`
- **THEN** the global sales table and summary cards refresh using the applied values
- **AND** the table contains only otherwise eligible sales whose `date` satisfies every supplied inclusive boundary
- **AND** the workspace visibly identifies the active applied filters or filtered result state

#### Scenario: Draft values do not change the sales list prematurely
- **WHEN** an authorized user changes a business or document-date control before selecting `Terapkan Filter`
- **THEN** the existing table, summaries, and applied-state feedback remain based on the previously applied values

#### Scenario: User supplies an incomplete or reversed sales date range
- **WHEN** an authorized user applies only one date boundary or enters a from date later than the to date
- **THEN** the system applies the supplied single boundary or normalizes the two boundaries into chronological order
- **AND** the visible controls and applied-state feedback reflect the effective range

#### Scenario: User resets sales filters
- **WHEN** an authorized user selects `Reset` in the global sales-payment filter panel
- **THEN** business and document-date filters are cleared from draft and applied state
- **AND** the table and summaries return to their unfiltered eligible global results

### Requirement: Durable global sales summary-card selection
The system SHALL preserve the visible selected summary-card state while global sales filters or summaries refresh.

#### Scenario: Selected card remains visible after filter application
- **WHEN** an authorized user selects an outstanding, overdue, or paid summary card and then applies a business or document-date filter
- **THEN** the same card remains visibly selected
- **AND** its payment-state condition remains composed with the applied filters, text search, and eligible-sales constraints

