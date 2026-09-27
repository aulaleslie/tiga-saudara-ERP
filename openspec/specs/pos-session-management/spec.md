## ADDED Requirements

### Requirement: POS sessions are globally unique per user
The system SHALL prevent a user from opening multiple concurrent POS sessions across all settings globally. Operations evaluating active session presence for a user MUST lock natively and check state without setting-level boundaries.

#### Scenario: User attempts to open a session when none exist globally
- **WHEN** a user with `pos.sessions.open` permission and no active sessions accesses the open session portal
- **THEN** the system permits opening the session
- **AND** a new POS Session is created

#### Scenario: User attempts to open a session in the same setting as their active session
- **WHEN** a user submits the "Buka Sesi" form in Setting X when they already hold an open POS session in Setting X
- **THEN** the system ignores the duplication and elegantly handles resumption
- **AND** the user is redirected or connected to the existing Setting X session

#### Scenario: User navigates to Open Session form when active in another setting
- **WHEN** a user accesses the POS session opening screen (`/pos/sessions/open`) in Setting Y
- **AND** the user already has an active POS session in Setting X
- **THEN** the form is disabled directly upon rendering
- **AND** a clear error message is prominently displayed indicating they have an active session in Setting X
- **AND** the submit button ("Buka Sesi") is hidden or disabled

#### Scenario: User attempts parallel HTTP POST to open sessions in different settings
- **WHEN** requests are made to concurrently open sessions in Setting X and Setting Y
- **THEN** exactly one request will succeed
- **AND** the database constraint `pos_sessions_global_active_user_unique` on `(cashier_user_id, active_marker)` SHALL block the secondary transaction
- **AND** the service logic also rejects the second request
