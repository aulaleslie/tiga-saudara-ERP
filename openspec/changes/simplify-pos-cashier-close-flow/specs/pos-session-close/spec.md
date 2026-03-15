## MODIFIED Requirements

### Requirement: Cashier closes session (simplified)
The cashier SHALL be able to close their active POS session. Closing releases the terminal for other cashiers to use and transitions the session to a CLOSED state. The close action is purely administrative and does not involve cash counting or variance approval.

#### Scenario: Successful close
- **WHEN** cashier initiates a session close for their own active session
- **THEN** the system transitions the session status from OPEN/CLOSING to CLOSED
- **THEN** the system records the closed_at timestamp
- **THEN** the system returns the session ID, new status (CLOSED), and closed_at to the client
- **THEN** no variance calculation or approval is required

#### Scenario: Optional close reason
- **WHEN** cashier provides an optional reason string when closing
- **THEN** the system records the reason in session metadata for audit purposes
- **THEN** the close proceeds and succeeds regardless of whether reason was provided

#### Scenario: Authorization check
- **WHEN** a user attempts to close a session they do not own or lack permission
- **THEN** the system returns a 403 error with message "Only the session cashier can close this session" or permission error
- **THEN** the session remains in its current state

### Requirement: No variance approval at close
The close action SHALL NOT calculate expected cash, compute variance, or require supervisor approval.

#### Scenario: Cash data is ignored
- **WHEN** a close request includes cash-related fields (counted_cash_total, denominations, supervisor_identifier, supervisor_pin)
- **THEN** the system accepts the request but ignores those fields
- **THEN** close succeeds with only session state change, no cash processing

#### Scenario: Supervisor involvement not requested
- **WHEN** cashier closes a session
- **THEN** no supervisor credentials are requested or validated
- **THEN** the response includes no approval-related fields

## REMOVED Requirements

### Requirement: Variance approval at close
**Reason**: Variance approval moved to the finalize stage per two-stage settlement design. Close is now pure terminal release.

**Migration**: All cash reconciliation and variance approval now happens via the separate finalize endpoint (`POST /pos/sessions/{id}/finalize`). Supervisors finalize CLOSED sessions to reconcile cash and approve variances.
