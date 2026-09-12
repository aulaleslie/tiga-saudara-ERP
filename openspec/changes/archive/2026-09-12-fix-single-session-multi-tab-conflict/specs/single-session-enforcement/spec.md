## ADDED Requirements

### Requirement: Single-session enforcement does not invalidate the same login's own concurrent requests
The system SHALL distinguish between concurrent requests belonging to the same authenticated login (e.g. multiple browser tabs sharing one session) and a request belonging to a genuinely different login for the same user, and SHALL NOT destroy a session that is still backing the same login as the current request.

#### Scenario: Same user opens a second tab in the same browser
- **WHEN** an authenticated user has one tab open on a long-running form and opens or uses a second tab under the same login
- **THEN** the system does not destroy the session backing either tab
- **AND** both tabs continue to function without an unexpected 419

#### Scenario: Concurrent requests race under the same login
- **WHEN** two or more requests from the same login arrive close enough together that their session-identity checks could observe a transient mismatch
- **THEN** the system resolves the check using a stable per-login identity rather than session-ID equality alone
- **AND** no session backing the current login is destroyed as a result of the race

### Requirement: A genuine second login still invalidates the prior session
The system SHALL continue to invalidate a user's previous session when a distinguishably different login (new authentication event, not merely a same-login request from another tab) is observed for that user.

#### Scenario: User logs in from a second device or browser
- **WHEN** a user who already has an active session authenticates again from a different device or browser
- **THEN** the system records a single-session conflict for the prior login
- **AND** destroys the prior login's session before continuing

### Requirement: Single-session conflict diagnostics remain correlatable
The system SHALL continue emitting `single_session_observed` and `single_session_invalidated` diagnostic events for genuine conflicts, using only privacy-safe fingerprinted identifiers, so that production behavior of the corrected logic remains verifiable.

#### Scenario: A genuine conflict is detected
- **WHEN** the system determines that a different login is displacing a user's prior session
- **THEN** it records the existing `single_session_observed` and `single_session_invalidated` events with fingerprinted identifiers
- **AND** does not record raw session IDs or login tokens
