## MODIFIED Requirements

### Requirement: Cashier closes session (simplified)
The cashier SHALL be able to close their active POS session. Closing releases the terminal for other cashiers to use and transitions the session to a CLOSED state. The close action is purely administrative and does not involve cash counting or variance approval. A manager bundle with explicit administrative session-close authority SHALL also be able to close any open POS session without owning it. Super Admin users SHALL continue to be able to initiate this close action for any open session via gate-level bypass.

#### Scenario: Cashier closes own session
- **WHEN** a cashier initiates a session close for their own active session
- **THEN** the system MUST transition the session status from OPEN or CLOSING to CLOSED
- **AND** the system MUST record the closed_at timestamp
- **AND** the system MUST return the session ID, new status, and closed_at to the client
- **AND** no variance calculation or approval is required

#### Scenario: Manager closes any session
- **WHEN** a manager with explicit administrative session-close authority initiates a close for an active POS session owned by another cashier
- **THEN** the system MUST allow the close to proceed without requiring session ownership
- **AND** the system MUST transition the session to CLOSED

#### Scenario: Super Admin closes any session
- **WHEN** a user with `Super Admin` role initiates a session close for an active session in a setting they are not assigned to
- **THEN** the system MUST allow the close to proceed without permission or setting assignment checks
- **AND** the system MUST transition the session status to CLOSED
- **AND** the close request with valid integer session ID SHALL be accepted

#### Scenario: Optional close reason
- **WHEN** cashier or manager provides an optional reason string when closing
- **THEN** the system MUST record the reason in session metadata for audit purposes
- **AND** the close MUST proceed regardless of whether reason was provided

#### Scenario: Unauthorized close attempt
- **WHEN** a user attempts to close a session without being the owning cashier and without administrative session-close authority
- **THEN** the system MUST return a 403 error
- **AND** the session MUST remain in its current state
