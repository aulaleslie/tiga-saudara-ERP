# pos-session-close Specification (Delta)

## MODIFIED Requirements

### Requirement: Cashier closes session (simplified)
The cashier SHALL be able to close their active POS session. Closing releases the terminal for other cashiers to use and transitions the session to a CLOSED state. The close action is purely administrative and does not involve cash counting or variance approval. **Super Admin users SHALL also be able to initiate this close action for any open session, bypassing the setting assignment check.**

#### Scenario: Successful close
- **WHEN** cashier initiates a session close for their own active session
- **THEN** the system transitions the session status from OPEN/CLOSING to CLOSED
- **THEN** the system records the closed_at timestamp
- **THEN** the system returns the session ID, new status (CLOSED), and closed_at to the client
- **THEN** no variance calculation or approval is required

#### Scenario: Super Admin closes any session
- **WHEN** a user with `Super Admin` role initiates a session close for an active session in a setting they are NOT assigned to
- **THEN** the system MUST allow the close to proceed
- **AND** the system MUST transition the session status to CLOSED
- **AND** the system MUST NOT require setting assignment in `user_setting` table

#### Scenario: Optional close reason
- **WHEN** cashier provides an optional reason string when closing
- **THEN** the system records the reason in session metadata for audit purposes
- **THEN** the close proceeds and succeeds regardless of whether reason was provided

#### Scenario: Authorization check
- **WHEN** a non-Super-Admin user attempts to close a session they do not own or lack permission
- **THEN** the system returns a 403 error with message "Only the session cashier can close this session" or permission error
- **THEN** the session remains in its current state
