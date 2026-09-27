## MODIFIED Requirements

### Requirement: GET /pos/sessions/{id}/summary endpoint handles exceptions gracefully
The endpoint SHALL catch exceptions from business logic and return appropriate JSON error responses instead of HTML error pages. DomainException (business logic errors) SHALL return 422 with the exception message. Other exceptions SHALL return 500 with a generic error message.

#### Scenario: Business logic error when calculating expected cash
- **WHEN** the expected cash calculator encounters a business logic error (e.g., unknown cash event direction)
- **THEN** the endpoint returns a 422 JSON response with the specific error message

#### Scenario: Database or infrastructure error
- **WHEN** the summary service encounters a database connection error, timeout, or other infrastructure failure
- **THEN** the endpoint returns a 500 JSON response with a generic "Internal server error" message

#### Scenario: Authorized user successfully requests session summary via AJAX
- **WHEN** an authenticated user (session owner or with pos.sessions.view permission) requests GET /pos/sessions/{id}/summary with Accept: application/json
- **THEN** the endpoint returns a 200 JSON response with session summary data

#### Scenario: Authorized user requests session summary for view rendering
- **WHEN** an authenticated user requests GET /pos/sessions/{id}/summary without Accept: application/json header
- **THEN** the endpoint returns an HTML page rendering the session detail view

#### Scenario: Unauthorized user requests endpoint
- **WHEN** an authenticated user without pos.sessions.view permission and not the session owner requests the endpoint
- **THEN** the endpoint returns a 403 Forbidden JSON response with message "Not authorized to view POS session summary."

#### Scenario: Unauthenticated user requests endpoint
- **WHEN** an unauthenticated user requests the endpoint
- **THEN** the endpoint returns a 403 error JSON response with message "Authentication is required."

#### Scenario: Non-existent session
- **WHEN** a user requests an endpoint with a session ID that does not exist in the current setting
- **THEN** the endpoint returns a 404 error JSON response with message "POS session not found for current setting."

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

### Requirement: GET /pos/sessions/{id}/summary endpoint displays all payment methods
The "Metode" (Payment Method) information for each transaction in the session summary SHALL include all unique payment methods used in the checkout. For multi-payment checkouts, these methods SHALL be aggregated into a comma-separated string (e.g., "MANDIRI, CASH").

#### Scenario: Multi-payment method display
- **WHEN** a checkout has payments [MANDIRI RAHMAT, CASH]
- **THEN** the session summary table displays "MANDIRI RAHMAT, CASH" in the "Metode" column for that transaction
