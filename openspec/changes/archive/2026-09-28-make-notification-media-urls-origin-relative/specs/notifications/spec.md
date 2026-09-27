# Spec Delta

## ADDED Requirements

### Requirement: Origin-Relative Notification Destinations
The system SHALL persist same-application notification action destinations as origin-relative paths and SHALL resolve them against the origin from which the authenticated user opened the notification.

#### Scenario: Notification opened from the local network
- **WHEN** a user opens a notification through the on-premise application origin
- **THEN** the system marks the notification as read and redirects to its destination on that same on-premise origin

#### Scenario: Notification opened through Cloudflare
- **WHEN** a user opens the same kind of notification through the Cloudflare application origin
- **THEN** the system marks the notification as read and redirects to its destination on that same Cloudflare origin

#### Scenario: Notification is generated without an HTTP request
- **WHEN** an Artisan command, queued job, or other background process generates a notification
- **THEN** the persisted same-application action destination does not embed the configured fallback application origin

### Requirement: Notification Redirect Destination Safety
The system MUST redirect notification clicks only to valid same-application destinations and MUST NOT use a protocol-relative or unrecognized external destination as a notification redirect target.

#### Scenario: Notification contains a valid relative destination
- **WHEN** an authorized user clicks a notification whose action destination is a valid application-relative path
- **THEN** the system redirects to that path after marking the notification as read

#### Scenario: Notification contains an unsafe destination
- **WHEN** an authorized user clicks a notification whose action destination is protocol-relative, malformed, or points to an unrecognized external origin
- **THEN** the system marks the notification as read without redirecting the user outside the application

### Requirement: Legacy Notification Destination Repair
The system SHALL provide an idempotent operator-controlled repair mechanism that converts notification destinations from explicitly recognized application origins into origin-relative paths without changing notification history or state.

#### Scenario: Legacy LAN destination is repaired
- **WHEN** the repair mechanism processes an absolute notification destination using the recognized on-premise application origin
- **THEN** it stores the destination path, query, and fragment without the origin

#### Scenario: Legacy Cloudflare destination is repaired
- **WHEN** the repair mechanism processes an absolute notification destination using the recognized Cloudflare application origin
- **THEN** it stores the destination path, query, and fragment without the origin

#### Scenario: Unrecognized absolute destination is encountered
- **WHEN** the repair mechanism encounters an absolute destination whose origin was not explicitly recognized by the operator
- **THEN** it leaves that destination unchanged and reports it for review

#### Scenario: Repair is repeated
- **WHEN** the repair mechanism runs again over already normalized notification destinations
- **THEN** it makes no further changes and preserves read, resolved, and creation state
