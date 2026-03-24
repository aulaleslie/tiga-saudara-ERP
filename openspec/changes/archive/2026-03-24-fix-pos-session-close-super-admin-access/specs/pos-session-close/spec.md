# pos-session-close Authorization & Type Safety Fix

## MODIFIED Requirements

### Requirement: Cashier closes session (simplified)
The cashier SHALL be able to close their active POS session. Closing releases the terminal for other cashiers to use and transitions the session to a CLOSED state. The close action is purely administrative and does not involve cash counting or variance approval. **Super Admin users SHALL also be able to initiate this close action for any open session, bypassing the setting assignment check.** Super Admin authorization SHALL NOT depend on permission assignment to the role; the application SHALL grant access via gate-level bypass.

#### Scenario: Successful close
- **WHEN** cashier initiates a session close for their own active session
- **THEN** the system transitions the session status from OPEN/CLOSING to CLOSED
- **THEN** the system records the closed_at timestamp
- **THEN** the system returns the session ID, new status (CLOSED), and closed_at to the client
- **THEN** no variance calculation or approval is required

#### Scenario: Super Admin closes any session
- **WHEN** a user with `Super Admin` role initiates a session close for an active session in a setting they are NOT assigned to
- **THEN** the system MUST allow the close to proceed without permission or setting assignment checks
- **AND** the system MUST transition the session status to CLOSED
- **AND** the system MUST NOT require setting assignment in `user_setting` table
- **AND** the close request with valid integer session ID SHALL be accepted (route parameter type safety)

#### Scenario: Optional close reason
- **WHEN** cashier provides an optional reason string when closing
- **THEN** the system records the reason in session metadata for audit purposes
- **THEN** the close proceeds and succeeds regardless of whether reason was provided

#### Scenario: Authorization check
- **WHEN** a user attempts to close a session they do not own or lack permission
- **THEN** the system returns a 403 error with message "Only the session cashier can close this session" or permission error
- **THEN** the session remains in its current state

## ADDED Requirements

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
