## Context

Laravel 10 converts CSRF token mismatches to HTTP 419 and excludes `TokenMismatchException` from normal reporting. Livewire 3.0.5 responds to a 419 with a native `confirm()` dialog, while conventional form submissions use the generic `resources/views/errors/419.blade.php` page. Neither path provides a support reference or enough server evidence to identify session expiry, missing session storage, cookie loss, or explicit invalidation.

The active-business endpoint changes `setting_id` and roles but does not rotate the session. Separately, `SingleSessionMiddleware` keeps one cached session ID per user and destroys the previously cached session whenever a different authenticated session is observed. This is a credible cause of apparently spontaneous 419 responses, but this change must observe the behavior before changing that policy.

The solution crosses the application exception handler, middleware, Setting module, shared Blade layouts/JavaScript, and focused tests. Diagnostic data must remain useful even when the destroyed session can no longer resolve an authenticated user, and it must never expose credentials, tokens, cookies, request bodies, or raw session identifiers.

## Goals / Non-Goals

**Goals:**

- Give every handled CSRF mismatch a short, copyable incident ID shared by the response and structured server log.
- Correlate a mismatch with earlier session invalidation and active-business switch events using one-way session fingerprints.
- Distinguish useful diagnostic signals such as cookie presence, empty/missing session state, route/request type, business context, page age, application node, and deployment version.
- Replace Livewire's native expiry confirmation and the generic 419 page with clear Indonesian recovery guidance.
- Warn users on transaction-oriented pages to verify saved state before retrying.
- Keep instrumentation failure isolated from the original response path.

**Non-Goals:**

- Changing the single-session policy, session lifetime, session driver, CSRF validation, or business-switch authorization.
- Automatically retrying a failed mutation or preserving arbitrary unsaved Livewire/form state.
- Proving the root cause before production evidence is collected.
- Introducing an external observability vendor, database incident table, or automated browser test suite.

## Decisions

### 1. Use a dedicated diagnostic service and structured application log events

A small application-level service will generate incident IDs, derive session fingerprints, normalize request context, and emit structured events. The exception handler, `SingleSessionMiddleware`, authentication/session rotation points, and business-switch controller will call this shared service instead of independently assembling logs.

The core event names will be stable and searchable: `csrf_mismatch`, `single_session_observed`, `single_session_invalidated`, `auth_session_rotated`, and `business_context_switched`. Each event includes an event timestamp, deployment/node identity when configured, request classification, and the minimum actor/business/session context available at that point.

Alternative considered: logging only `TokenMismatchException`. Rejected because after session loss the mismatch often has no authenticated user and cannot explain which earlier event destroyed or rotated the session.

### 2. Correlate by keyed, one-way fingerprints and never record raw secrets

Session IDs and CSRF values will be fingerprinted with a keyed HMAC based on application secret material and truncated only to a length that remains operationally collision-resistant. Logs will record whether the session cookie and submitted token were present and whether fingerprints matched, but never their raw values. The logger will use an explicit allowlist; it will not serialize request input, cookies, headers wholesale, Livewire payloads, or exception context containing those values.

The session fingerprint logged immediately before single-session destruction can therefore be matched with the fingerprint on a later 419 request even if authentication data has disappeared.

Alternative considered: logging raw session/token values temporarily. Rejected because logs commonly have broader retention and access than the session store and would become an authentication-secret exposure.

### 3. Add lightweight client page context to Livewire requests

The shared authenticated layout will expose non-sensitive page context: a random page-view ID, per-tab ID, page render time, business ID at render, route name, and deployment identifier. A global Livewire `request` hook will add allowlisted diagnostic headers to outgoing Livewire requests. Values received from the client will be length-limited, normalized, and marked as client-supplied rather than trusted authorization data.

Conventional 419 responses may have less page context. They will still be correlated through the server-side session fingerprint, request route/referrer path, business value remaining in the session, and causal lifecycle logs. This avoids invasive changes to every existing form.

Alternative considered: sending the full component snapshot or form state to a reporting endpoint. Rejected because it duplicates sensitive business data and the reporting request itself may fail CSRF validation.

### 4. Handle 419 centrally and return an incident-aware response contract

The exception handler will explicitly handle `TokenMismatchException`, generate and log the incident before rendering, and return status 419 with an `X-ERP-Incident-ID` header. Livewire/AJAX requests will receive a small JSON response containing the incident ID, localized message, recovery guidance, and a transaction-safety flag. Conventional requests will render the improved 419 view with the same fields.

The response will use `Cache-Control: no-store`. Logging is best-effort: failures in diagnostic generation or emission must fall back to a generic incident reference and must not replace or change the 419 status.

Alternative considered: relying only on the standard error page. Rejected because Livewire intercepts failed requests and currently presents its own native confirmation.

### 5. Replace Livewire's default confirmation through its request failure hook

Shared JavaScript loaded after Livewire initialization will register a global `request` failure hook. For status 419 it will call `preventDefault()`, parse only the expected incident response fields, and display the existing SweetAlert-based modal. Other failure statuses remain under Livewire's default behavior.

The modal will explain that the session is no longer valid, show and copy the incident ID, offer reload and close actions, and tell the user to sign in again if redirected. On known mutation-sensitive route families such as Purchase, receiving, payment, sale, return, stock, and POS pages, it will additionally instruct the user to verify whether the previous operation was saved before retrying. It will not automatically reload or repeat a request.

If SweetAlert or response parsing is unavailable, a minimal application-owned fallback dialog/page will still present the incident ID and reload instruction.

Alternative considered: automatically refreshing the CSRF token and replaying the request. Rejected because session expiry may also mean lost authentication/business context, and replaying financial or inventory mutations could duplicate work.

### 6. Observe business switching without changing its behavior

After authorization and immediately around the successful `setting_id` transition, the Setting module will log the old and new business IDs, authenticated user ID, and session fingerprint. Denied attempts retain existing behavior and may be logged without revealing business existence to the response. The endpoint continues redirecting to Home and does not regenerate the session.

This event allows support to determine whether a 419 followed a business change without asserting that the switch caused it.

### 7. Use focused automated tests and human browser verification

Focused tests will cover redaction/fingerprinting, exception response variants, incident correlation fields, single-session invalidation logging, business-switch logging, and graceful logging failure. Tests will assert response contracts and rendered modal integration points without adding a browser automation suite.

Human verification will exercise business switching followed by Purchase navigation, a forced stale Livewire token, a conventional stale form, copy/reload controls, responsive modal rendering, and the transaction retry warning.

## Risks / Trade-offs

- **[Client diagnostic context can be spoofed]** → Treat it as a troubleshooting hint only, normalize and length-limit it, and never use it for authorization or security decisions.
- **[High-volume normal request logging]** → Keep `single_session_observed` at debug level or emit it only on a fingerprint difference; emit invalidation and CSRF incidents at warning level.
- **[HMAC fingerprints become unstable after application-key rotation]** → Include a non-secret fingerprint version/deployment marker and correlate within the operational incident window only.
- **[Exception-handler logging can itself fail]** → Wrap diagnostic emission defensively and preserve a valid 419 response with generic guidance.
- **[Route-family classification misses a transaction page]** → Default to safe generic instructions and maintain a centralized classification list that can be expanded without changing response semantics.
- **[File sessions or cache are node-local in a multi-node deployment]** → Include node, session driver, and cache driver metadata so evidence exposes cross-node inconsistency; changing infrastructure remains a follow-up.
- **[Users may lose unsaved work after reload]** → Never reload automatically; state the consequence clearly and provide Close as an alternative.
- **[Additional diagnostic headers marginally increase request size]** → Use short identifiers and a strict allowlist rather than serialized state.

## Migration Plan

1. Deploy the diagnostic service and causal event logging first or atomically with the response handlers.
2. Deploy the incident-aware exception response, shared Livewire hook, modal, and updated 419 view.
3. Confirm production log ingestion retains structured fields and that sensitive-field checks pass.
4. Perform the documented human browser scenarios in staging, then monitor incident IDs and correlated lifecycle events in production.
5. Use collected evidence to propose a separate root-cause correction if the single-session middleware, session backend, cookie configuration, timeout, or another cause is confirmed.

Rollback consists of removing the custom Livewire hook and custom exception rendering while leaving low-risk causal logging in place, or reverting the whole change. No schema or stored-data rollback is required.

## Open Questions

- Which deployment/node identifier and release version variables are reliably available in production?
- What log retention and admin search workflow should be documented for incident IDs?
- Should the final root-cause change preserve strict one-session-per-user behavior or replace it with explicit logout of the displaced client? This is intentionally deferred until evidence exists.
