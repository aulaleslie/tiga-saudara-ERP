## MODIFIED Requirements

### Requirement: Manual Refresh MUST Retry With Active Filters
The `Muat Data` action SHALL retry list loading using currently selected filter inputs, and empty status selection SHALL be interpreted as all statuses.

#### Scenario: User retries after previous failure
- **WHEN** the user clicks `Muat Data` after a failed request
- **THEN** the system MUST send a new data request and update the table based on the latest filter values

#### Scenario: Empty status filter loads all statuses
- **WHEN** user clicks `Muat Data` while no status filter is selected
- **THEN** the request MUST omit status restriction and the list MUST include draft and completed transactions

#### Scenario: Explicit status filter narrows results
- **WHEN** user clicks `Muat Data` with one or more statuses selected
- **THEN** the list MUST return only transactions matching selected statuses
