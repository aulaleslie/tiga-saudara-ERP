## ADDED Requirements

### Requirement: Cashier can release terminal without cash counting
The cashier SHALL be able to release a POS terminal immediately without counting cash or obtaining supervisor approval. The action is purely administrative: marking the terminal as available for the next cashier.

#### Scenario: Cashier releases terminal
- **WHEN** cashier clicks the close terminal button on the sell page
- **THEN** the system releases the terminal immediately, transitions the session status to CLOSED, and returns to the home page
- **THEN** the terminal is now available for another cashier to open a new session

#### Scenario: Cashier provides optional reason
- **WHEN** cashier closes the terminal and optionally provides a reason (e.g., "break", "shift end")
- **THEN** the system records the reason in the session metadata for audit purposes
- **THEN** the terminal is released regardless of whether reason was provided

#### Scenario: System transitions session correctly
- **WHEN** a session in OPEN or CLOSING status closes
- **THEN** the system transitions the session status to CLOSED
- **THEN** the system records `closed_at` timestamp
- **THEN** no variance calculation or approval checks are performed

### Requirement: No cash counting during close
The close action SHALL NOT request or process any cash counting information. All cash reconciliation is deferred to the finalize stage.

#### Scenario: Close endpoint rejects cash data
- **WHEN** a request includes cash-related fields (e.g., counted_cash_total, denominations, supervisor credentials)
- **THEN** the system ignores those fields and processes only the session release
- **THEN** close succeeds without variance validation

### Requirement: Close provides immediate feedback
The close action SHALL complete immediately with a success response, providing the new session status and closed timestamp.

#### Scenario: Close returns session details
- **WHEN** close succeeds
- **THEN** the system returns JSON with: session_id, status (CLOSED), closed_at timestamp
- **THEN** no blocking or approval information is included in the response
