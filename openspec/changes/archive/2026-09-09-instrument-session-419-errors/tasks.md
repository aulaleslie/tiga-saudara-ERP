## 1. Diagnostic Foundation

- [x] 1.1 Add a session incident diagnostic service that generates support incident IDs, derives versioned keyed fingerprints, normalizes allowlisted request/page context, and classifies transaction-sensitive routes.
- [x] 1.2 Add configuration for optional deployment and application-node identifiers with safe fallbacks for environments where they are unavailable.
- [x] 1.3 Add focused unit tests proving deterministic correlation fingerprints, incident ID shape, context length limits, transaction-route classification, and exclusion of raw session IDs, CSRF tokens, cookies, request bodies, credentials, and Livewire payloads.

## 2. Session Lifecycle and Business-Switch Events

- [x] 2.1 Instrument `SingleSessionMiddleware` to emit structured conflict and invalidation events before destroying a displaced session, while preserving current single-session behavior and avoiding routine high-volume warning logs.
- [x] 2.2 Instrument the application's current login, logout, and explicit session-regeneration paths with safe before/after fingerprints and rotation causes where those values are available.
- [x] 2.3 Instrument authorized active-business switching with old/new business IDs, user ID, and safe session fingerprint without changing authorization, role synchronization, or Home redirection.
- [x] 2.4 Add focused middleware/controller tests for conflicting-session correlation, lifecycle-event redaction, successful business-switch events, unchanged denied-switch non-disclosure, and best-effort behavior when logging fails.

## 3. Incident-Aware 419 Responses

- [x] 3.1 Add central `TokenMismatchException` handling that emits `csrf_mismatch`, preserves status 419, and returns the incident ID through `X-ERP-Incident-ID` with `Cache-Control: no-store`.
- [x] 3.2 Return a minimal incident JSON contract for Livewire/AJAX failures and render an improved localized 419 page for conventional requests, including transaction-safety guidance when classified as sensitive.
- [x] 3.3 Ensure diagnostic failures fall back to a valid generic 419 response and cannot expose exception context or alter the primary error status.
- [x] 3.4 Add focused feature tests for Livewire and conventional response contracts, matching response/log incident IDs, destroyed-session correlation fields, no-store headers, sensitive-route guidance, and logging-failure fallback.

## 4. Shared Livewire Recovery Experience

- [x] 4.1 Add shared page-view metadata and per-tab context to authenticated layouts, then attach only the allowlisted values to Livewire requests through the global request hook.
- [x] 4.2 Add a shared Indonesian SweetAlert session-expiry modal that suppresses Livewire's native 419 confirmation and provides Copy Incident ID, Reload Page, and Close actions.
- [x] 4.3 Add transaction-specific retry warnings, prevent automatic request replay or reload, and provide a minimal fallback when SweetAlert or incident JSON parsing is unavailable.
- [x] 4.4 Add focused view/JavaScript contract tests or static assertions for hook registration, 419-only interception, incident display/copy controls, explicit reload behavior, fallback behavior, and transaction warning content.

## 5. Operational Verification and Handoff

- [x] 5.1 Document how administrators search an incident ID and correlate `csrf_mismatch`, `single_session_invalidated`, `auth_session_rotated`, and `business_context_switched` events, including the meaning and trust level of each field.
- [x] 5.2 Run only the focused automated tests added or directly affected by this change; do not require a full application test-suite run.
- [x] 5.3 Prepare a human browser checklist covering business switch then Purchase access, forced stale Livewire and conventional form tokens, modal responsiveness, copy/reload/close controls, sign-in recovery, transaction retry warning, and confirmation that no native Livewire dialog appears.
