# pos-session-close Specification

## Purpose
TBD - created by archiving change simplify-pos-cashier-close-flow. Update Purpose after archive.
## Requirements
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

### Requirement: Super Admin role initialization with all permissions
The system SHALL ensure that the "Super Admin" role is initialized with all available application permissions when the `SuperUserSeeder` runs.

#### Scenario: Super Admin role creation
- **WHEN** the `SuperUserSeeder` executes during application setup
- **THEN** the "Super Admin" role is created if it does not exist
- **AND** all available permissions are synced to the "Super Admin" role
- **AND** a Super Admin user is created with the Super Admin role assigned

#### Scenario: Permission gate bypass
- **WHEN** a user with the "Super Admin" role attempts any action with a permission gate
- **THEN** the authorization gate SHALL return `true` (allow) before checking explicit permissions
- **AND** Super Admin can perform any application action without explicit permission assignment

### Requirement: Route parameter type safety for session endpoints
All POS session routes that accept a `{session}` parameter SHALL enforce integer type constraints to prevent type errors.

#### Scenario: Valid integer session ID
- **WHEN** a request is made to `/pos/sessions/123/close` (valid integer)
- **THEN** the route matches and passes the integer `123` to the controller
- **AND** the controller receives the parameter as type `int`

#### Scenario: Invalid session ID format
- **WHEN** a request is made to `/pos/sessions/abc/close` (non-numeric)
- **THEN** the route does NOT match
- **AND** the system returns a 404 Not Found response
- **AND** no type error occurs

#### Scenario: Type constraint applies to all session routes
- **WHEN** requests are made to any of these endpoints: `/pos/sessions/{session}/summary`, `/pos/sessions/{session}/close`, `/pos/sessions/{session}/finalize`, `/pos/sessions/{session}/safe-drops`, `/pos/sessions/{session}/pickup`
- **THEN** all endpoints enforce integer type constraint on the `{session}` parameter
- **AND** all endpoints benefit from type safety and consistent error handling
