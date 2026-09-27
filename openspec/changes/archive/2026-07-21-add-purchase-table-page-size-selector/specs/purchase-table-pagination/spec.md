## ADDED Requirements

### Requirement: Purchase table page size selection
The system SHALL provide a dropdown selector to allow users to specify how many records are displayed per page on the Purchase table. The options SHALL be 10, 25, 50, and 100.

#### Scenario: User selects a different page size
- **WHEN** the user selects "50" from the records per page dropdown
- **THEN** the table re-renders to display up to 50 records
- **AND** the pagination links reflect the new total number of pages

#### Scenario: Resetting page to 1 on size change
- **WHEN** the user is on page 3 of the table
- **AND** the user changes the page size from 10 to 50
- **THEN** the table re-renders displaying up to 50 records
- **AND** the table resets the active page to page 1
