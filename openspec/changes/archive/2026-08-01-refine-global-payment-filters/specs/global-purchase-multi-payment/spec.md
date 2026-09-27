## ADDED Requirements

### Requirement: Explicit global purchase payment filter application
The system SHALL present the global purchase-payment business and document-date controls as a single filter panel with explicit application, reset, and applied-state feedback.

#### Scenario: User applies a purchase document-date filter
- **WHEN** an authorized user enters a business and/or one or both `Tanggal Dokumen` boundaries and selects `Terapkan Filter`
- **THEN** the global purchase table and summary cards refresh using the applied values
- **AND** the table contains only otherwise eligible purchases whose `date` satisfies every supplied inclusive boundary
- **AND** the workspace visibly identifies the active applied filters or filtered result state

#### Scenario: Draft values do not change the purchase list prematurely
- **WHEN** an authorized user changes a business or document-date control before selecting `Terapkan Filter`
- **THEN** the existing table, summaries, and applied-state feedback remain based on the previously applied values

#### Scenario: User supplies an incomplete or reversed purchase date range
- **WHEN** an authorized user applies only one date boundary or enters a from date later than the to date
- **THEN** the system applies the supplied single boundary or normalizes the two boundaries into chronological order
- **AND** the visible controls and applied-state feedback reflect the effective range

#### Scenario: User resets purchase filters
- **WHEN** an authorized user selects `Reset` in the global purchase-payment filter panel
- **THEN** business and document-date filters are cleared from draft and applied state
- **AND** the table and summaries return to their unfiltered eligible global results
