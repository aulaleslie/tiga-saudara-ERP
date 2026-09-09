## Why

Users intermittently receive HTTP 419 responses during normal ERP operation, most visibly when opening Purchase after changing the active business, but the application currently neither records enough session context to identify the cause nor gives the user a useful incident reference. Instrumenting the session lifecycle and replacing Livewire's native expiry dialog will make future incidents diagnosable while giving users safe, clear recovery instructions.

## What Changes

- Record structured, privacy-safe diagnostic events for CSRF mismatches, session invalidation, authentication session rotation, and active-business changes.
- Assign every handled 419 response a support incident ID that can be correlated with server logs.
- Attach limited page, tab, deployment, route, business, and session-fingerprint context without recording raw CSRF tokens, session IDs, cookies, or business-form payloads.
- Replace Livewire's native browser expiry confirmation with an Indonesian application modal that explains recovery, warns users to verify transaction state before retrying, and supports copying the incident ID.
- Replace the generic full-page 419 response with equivalent guidance for non-Livewire requests.
- Add focused automated coverage for diagnostic event generation, redaction, business-switch correlation, and 419 response contracts; interactive browser behavior remains a human verification activity.
- Preserve existing authorization, session lifetime, and single-session policy in this diagnostic change; use the resulting evidence to select a separate behavioral fix later.

## Capabilities

### New Capabilities

- `session-expiry-observability`: Privacy-safe session/CSRF incident diagnostics, incident correlation, and user-facing recovery behavior for Livewire and conventional requests.

### Modified Capabilities

- `active-business-switch-security`: Successful authorized business switches also emit a correlation-safe context-change event without weakening existing authorization or redirect behavior.

## Impact

- Affects Laravel exception handling, web middleware, session lifecycle diagnostics, active-business switching, shared layouts/JavaScript, the 419 error view, and focused tests.
- Adds structured log events and diagnostic response metadata; no new external service or browser-test framework is required.
- Applies globally because CSRF/session expiry is cross-cutting, while retaining Purchase-specific safety wording for potentially state-changing operations.
- Does not change database schemas, public business APIs, business authorization rules, or the configured session-expiry policy.
