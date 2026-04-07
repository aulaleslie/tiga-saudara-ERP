## ADDED Requirements

### Requirement: Transaction Loading Concurrency Enforcement
The system SHALL ensure that a POS transaction can only be in one active cart at any given time to prevent conflicting edits in different sessions.

#### Scenario: Transaction already loaded in a session
- **WHEN** an authorized user attempts to load a transaction that is currently in `LOADED` status
- **THEN** the system MUST reject the request with a `409 Conflict` error and provide a message explaining that the transaction is currently in use.

#### Scenario: Simultaneous loading requests
- **WHEN** multiple users attempt to load the same `DRAFT` transaction at the exact same time
- **THEN** the server MUST atomically process only the first request and reject all others with a `409 Conflict` error, ensuring data integrity.

### Requirement: Conditional Load Action Visibility
The POS transaction list UI SHALL hide the "Load" (Muat) action for any transaction that has an active `LOADED` status.

#### Scenario: User views transaction list
- **WHEN** the transaction list is rendered
- **THEN** the "Load" button MUST NOT be visible for rows with status `LOADED`.
