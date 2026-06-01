## ADDED Requirements

### Requirement: Purchase index due date column
The system SHALL display `Tanggal Jatuh Tempo` on the operational `/purchases` list for each purchase row.

#### Scenario: Due date is visible on purchase list
- **WHEN** a user opens the `/purchases` page
- **THEN** the purchase table includes a `Tanggal Jatuh Tempo` column
- **AND** each purchase row displays the purchase `due_date` formatted consistently with the existing purchase date display

#### Scenario: Missing due date displays safely
- **WHEN** a purchase row has no due date value
- **THEN** the `Tanggal Jatuh Tempo` cell displays `-`
- **AND** the purchase list remains renderable

### Requirement: Purchase index due date sorting
The system SHALL allow users to sort the operational `/purchases` list by `Tanggal Jatuh Tempo`.

#### Scenario: User sorts by due date
- **WHEN** a user clicks the `Tanggal Jatuh Tempo` column header
- **THEN** the purchase list sorts by `purchases.due_date`
- **AND** clicking the same column header again reverses the sort direction

#### Scenario: Due date sort preserves existing filters
- **WHEN** the purchase list is filtered by search text, archive visibility, supplier, status, or summary-card filter
- **AND** the user sorts by `Tanggal Jatuh Tempo`
- **THEN** the system preserves the active filters while applying the due-date sort
