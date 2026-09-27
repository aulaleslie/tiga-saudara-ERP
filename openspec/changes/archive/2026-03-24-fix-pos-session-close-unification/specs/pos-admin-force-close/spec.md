## MODIFIED Requirements

### Requirement: Admin force-close OPEN terminals
Admin users (with `pos.sessions.close-admin` permission) SHALL be able to immediately close OPEN POS sessions without waiting for the cashier to count cash, releasing the terminal for use by other cashiers. The force-close action SHALL transition the session from OPEN directly to CLOSED status and create a cash event recording the close action. **Super Admin users SHALL be able to perform this action even if not assigned to the terminal's business setting.**

#### Scenario: Admin closes terminal via sessions index

- **WHEN** an authenticated user with `pos.sessions.close-admin` permission views the `/pos/sessions` index
- **THEN** the user SHALL see an action button or dropdown menu with "Close Terminal (Admin)" option for each OPEN session
- **AND** clicking this action SHALL display a confirmation modal showing session details (terminal, cashier, opened_at, opening_float_total)

#### Scenario: Super Admin force-closes terminal without setting assignment
- **WHEN** a user with `Super Admin` role attempts to force-close an OPEN session in a setting they are NOT assigned to via `/pos/sessions/{id}/close`
- **THEN** the system MUST allow the action to proceed
- **AND** the system MUST NOT throw an "Admin user is not assigned to current setting" error
- **AND** the session status SHALL change from OPEN to CLOSED

#### Scenario: Force-close action completes successfully

- **WHEN** admin clicks "Confirm" in the force-close modal
- **THEN** the session status SHALL change from OPEN to CLOSED
- **AND** the session's `closed_by` field SHALL record the admin user's ID
- **AND** the session's metadata SHALL contain `closed_by_role: 'admin'`
- **AND** a PosSessionCashEvent of type EVENT_CLOSE_COUNT SHALL be created with direction DIRECTION_NEUTRAL
- **AND** the response SHALL include `status: 'CLOSED'` and `closed_at` timestamp
- **AND** the UI SHALL show a success message "Terminal closed successfully"

#### Scenario: Admin force-close is not available for CLOSED or FINALIZED sessions

- **WHEN** admin views the sessions index
- **THEN** the "Close Terminal (Admin)" button SHALL only appear for sessions with status OPEN
- **AND** the button SHALL be disabled/hidden for sessions with status CLOSED or FINALIZED

#### Scenario: User without permission cannot access force-close

- **WHEN** an authenticated user WITHOUT `pos.sessions.close-admin` permission attempts POST `/pos/sessions/{session}/close-admin`
- **THEN** the system SHALL return HTTP 403 Forbidden
- **AND** the session status SHALL remain unchanged

#### Scenario: Force-close terminal locked for concurrent access
- **WHEN** admin initiates force-close on a session
- **THEN** the session record SHALL be locked with SELECT FOR UPDATE during the close operation
- **AND** concurrent requests to modify the same session SHALL wait or fail gracefully
