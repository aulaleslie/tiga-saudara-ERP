# session-expiry-observability Specification

## Purpose
Provide privacy-safe session/CSRF incident diagnostics, incident correlation, and user-facing recovery behavior for Livewire and conventional requests so that HTTP 419 session-expiry incidents are diagnosable without exposing sensitive session data.

## Requirements

### Requirement: CSRF mismatches produce correlatable incidents
The system SHALL assign a support incident ID to every handled CSRF token mismatch and SHALL emit a structured `csrf_mismatch` diagnostic event carrying the same incident ID before returning an HTTP 419 response.

#### Scenario: Livewire request has an invalid CSRF token
- **WHEN** a Livewire request fails CSRF verification
- **THEN** the system returns status 419 with the incident ID in the defined response header and JSON response contract
- **AND** the system records a `csrf_mismatch` event with the same incident ID

#### Scenario: Conventional form has an invalid CSRF token
- **WHEN** a non-Livewire web request fails CSRF verification
- **THEN** the system returns status 419 with the incident ID in the response header and rendered error page
- **AND** the system records a `csrf_mismatch` event with the same incident ID

#### Scenario: Diagnostic logging fails
- **WHEN** diagnostic context generation or log emission fails while handling a CSRF mismatch
- **THEN** the system still returns a valid status 419 response with generic recovery guidance
- **AND** the diagnostic failure does not replace the original error response

### Requirement: Session diagnostics are privacy-safe
The system MUST use an explicit allowlist for session diagnostic fields and MUST NOT record raw session IDs, raw CSRF tokens, cookie values, credentials, request bodies, Livewire component payloads, or unrestricted request headers.

#### Scenario: Session and token values are available
- **WHEN** a diagnostic event is generated for a request containing a session ID and CSRF token
- **THEN** the event records only keyed one-way fingerprints and presence or comparison indicators for those values
- **AND** the raw values do not appear in the event

#### Scenario: Request contains business data and credentials
- **WHEN** a CSRF mismatch request contains form fields, cookies, authorization data, or a Livewire payload
- **THEN** the diagnostic event excludes those values except for explicitly allowlisted, normalized metadata

### Requirement: Session lifecycle causes are observable
The system SHALL emit structured diagnostic events when the single-session mechanism observes a conflicting session and before it invalidates a session, and SHALL record authentication session rotations at instrumented login, logout, or regeneration points.

#### Scenario: Another session displaces the current session
- **WHEN** the single-session mechanism observes a cached session fingerprint different from the authenticated request's fingerprint
- **THEN** the system records the user ID and both safe fingerprints before invalidation
- **AND** it records which session fingerprint is being invalidated

#### Scenario: Invalidated session later produces a 419
- **WHEN** a later CSRF mismatch uses the fingerprint previously recorded as invalidated
- **THEN** an administrator can correlate the `csrf_mismatch` and `single_session_invalidated` events using that fingerprint

#### Scenario: Authentication rotates a session
- **WHEN** an instrumented authentication flow regenerates or invalidates a session
- **THEN** the system records the rotation cause and available safe before/after fingerprints without recording either raw session ID

### Requirement: Diagnostic context identifies the operational environment
The system SHALL include available route, request type, authentication, business, page-age, deployment, application-node, session-driver, and cache-driver context in CSRF mismatch diagnostics without treating client-supplied context as trusted security data.

#### Scenario: Livewire page supplies diagnostic headers
- **WHEN** a Livewire request includes the allowlisted page-view, tab, render-time, rendered-business, route, and deployment context
- **THEN** the system normalizes and length-limits those values
- **AND** records them as client-supplied troubleshooting context

#### Scenario: Session authentication data has already disappeared
- **WHEN** a CSRF mismatch occurs after the backing session was destroyed and no user can be resolved
- **THEN** the event still contains the incident ID, session fingerprint when available, route/request classification, cookie-presence indicator, and environment context

### Requirement: Livewire session expiry uses an application modal
The system SHALL suppress Livewire's native 419 confirmation and display an Indonesian application modal containing the incident ID, recovery instructions, and explicit user-controlled actions.

#### Scenario: Livewire receives an incident-aware 419
- **WHEN** the shared Livewire request hook receives status 419 with the incident response contract
- **THEN** it prevents Livewire's default expiry confirmation
- **AND** displays the application modal with Copy Incident ID, Reload Page, and Close actions

#### Scenario: User chooses reload
- **WHEN** the user selects Reload Page in the expiry modal
- **THEN** the browser reloads only after that explicit selection
- **AND** the failed request is not automatically replayed

#### Scenario: Modal dependency or response parsing is unavailable
- **WHEN** the rich modal cannot be displayed or the 419 payload cannot be parsed
- **THEN** the application presents fallback expiry guidance and a usable incident reference when available

### Requirement: Recovery guidance protects transaction operations
The system SHALL warn users on identified financial, purchasing, sales, return, stock, receiving, payment, and POS contexts to verify whether the prior operation was saved before attempting it again.

#### Scenario: Purchase mutation encounters session expiry
- **WHEN** a 419 occurs from an identified Purchase transaction context
- **THEN** the recovery UI tells the user not to repeat the transaction until its saved state has been checked
- **AND** provides the incident ID to report to an administrator if the issue recurs

#### Scenario: Read-only page encounters session expiry
- **WHEN** a 419 occurs outside an identified transaction-sensitive context
- **THEN** the recovery UI provides generic reload and sign-in guidance without claiming that a mutation was saved or lost

### Requirement: Conventional 419 responses provide equivalent support guidance
The system SHALL render a branded, localized 419 page for non-Livewire requests with the incident ID, reload/sign-in instructions, and transaction-safety guidance when applicable.

#### Scenario: Conventional request receives 419 page
- **WHEN** a conventional form or web request fails CSRF verification
- **THEN** the response page explains that the session is no longer valid
- **AND** shows the copyable incident ID and user-controlled recovery navigation

### Requirement: 419 responses are not cached
Every custom 419 response SHALL instruct clients and intermediary caches not to store the response.

#### Scenario: Incident response is generated
- **WHEN** either a Livewire or conventional 419 response is returned
- **THEN** the response includes `Cache-Control: no-store`
