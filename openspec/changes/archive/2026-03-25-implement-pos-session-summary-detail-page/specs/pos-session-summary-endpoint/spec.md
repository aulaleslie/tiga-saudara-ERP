## MODIFIED Requirements

### Requirement: GET /pos/sessions/{id}/summary endpoint response type
The endpoint SHALL return an HTML Blade view rendering instead of a JSON response. The view SHALL be populated with session summary data (expected cash, cash events, transactions, etc.) calculated by PosSessionSummaryService. All authorization logic and data structure remain unchanged.

#### Scenario: Authorized user requests session summary
- **WHEN** an authenticated user (session owner or with pos.sessions.view permission) requests GET /pos/sessions/{id}/summary
- **THEN** the endpoint returns an HTML page (not JSON) displaying the session detail view

#### Scenario: Unauthorized user requests endpoint
- **WHEN** an authenticated user without pos.sessions.view permission and not the session owner requests the endpoint
- **THEN** the endpoint returns a 403 Forbidden response with message "Not authorized to view POS session summary."

#### Scenario: Unauthenticated user requests endpoint
- **WHEN** an unauthenticated user requests the endpoint
- **THEN** the endpoint returns a 403 error with message "Authentication is required."

#### Scenario: Non-existent session
- **WHEN** a user requests an endpoint with a session ID that does not exist in the current setting
- **THEN** the endpoint returns a 404 error with message "POS session not found for current setting."
