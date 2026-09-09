# Session/CSRF Incident Diagnostics — Operations Guide

## Searching an incident

Every handled 419 response includes an `X-ERP-Incident-ID` response header (e.g. `ERP-AB12CD34EF`) and the same ID appears in the Livewire JSON payload / rendered 419 page shown to the user. Users are asked to copy and report this ID.

To investigate, search the application log for the incident ID. It appears in exactly one `csrf_mismatch` log entry:

```
grep 'ERP-AB12CD34EF' storage/logs/laravel.log
```

## Correlating events

Diagnostic events are structured log entries with an `event` field. Search and correlate on `event` plus the shared `session_fingerprint` (and, if present, `user_id`) rather than on time alone:

| Event | Emitted by | Meaning |
|---|---|---|
| `csrf_mismatch` | Exception handler | A CSRF token mismatch produced a 419. Carries `incident_id`, `session_fingerprint`, route/request classification, and any client-supplied page context. |
| `single_session_observed` (debug) | `SingleSessionMiddleware` | A session fingerprint different from the cached one was seen for a user. Debug-level only; not indicative of a problem by itself. |
| `single_session_invalidated` (warning) | `SingleSessionMiddleware` | The previously cached session for a user was just destroyed because a different session was observed. Carries `invalidated_session_fingerprint` and `current_session_fingerprint`. |
| `auth_session_rotated` | `LoginController` | Login or logout regenerated/invalidated the session. Carries `cause` (`login`/`logout`), `before_session_fingerprint`, `after_session_fingerprint`, `user_id`. |
| `business_context_switched` | `BusinessController::updateActiveBusiness` | A user successfully switched active business. Carries `old_business_id`, `new_business_id`, `user_id`, `session_fingerprint`. |

### Typical correlation workflow

1. Look up the `csrf_mismatch` entry for the reported incident ID. Note its `session_fingerprint` (may be null if the session was already gone).
2. If a fingerprint is present, search for that same fingerprint value as either `current_session_fingerprint` or `invalidated_session_fingerprint` in `single_session_invalidated` entries, or as `session_fingerprint` in `business_context_switched` entries, within the preceding minutes.
3. A `single_session_invalidated` match strongly suggests the single-session mechanism destroyed the session the user was still using. A nearby `business_context_switched` match indicates the mismatch followed a business switch, without asserting the switch caused it.

## Field trust levels

- **Server-derived fields** (`session_fingerprint`, `user_id`, `route_name`, `session_cookie_present`, environment/deployment fields): generated from authoritative request/session state. Trustworthy for correlation.
- **Client-supplied fields** (`page_view_id`, `tab_id`, `page_rendered_at`, `page_business_id`, `page_route_name` — sent via `X-ERP-Page-*` headers): populated from values the browser reports. Treat as troubleshooting hints only; never use for authorization or as proof of a specific client state, since they can be stale, missing, or spoofed.
- **Fingerprints** are one-way keyed HMACs (versioned, e.g. `v1:...`), never raw session IDs or CSRF tokens. Two fingerprints only match if generated from the identical underlying value with the same application key and fingerprint version.

## Fields never logged

Raw session IDs, raw CSRF tokens, cookie values, request bodies, credentials, and Livewire component payloads are excluded by design (`App\Services\SessionIncidentDiagnosticsService`). If a value doesn't appear in the allowlist above, it was intentionally left out.

## Human browser verification checklist

Run manually in staging before/after deploying this change; not automated.

1. **Business switch then Purchase access**: switch active business, then open Purchase. Confirm no unexpected 419, or if one occurs, confirm the modal/page shows an incident ID.
2. **Forced stale Livewire token**: open a Livewire-heavy page (e.g. a Sale form), invalidate the session server-side (e.g. clear cache key or wait out expiry), trigger a Livewire action. Confirm the application modal appears (not Livewire's native `confirm()`), shows the incident ID, and offers Reload/Copy/Close.
3. **Forced stale conventional form**: same as above but with a non-Livewire form submission. Confirm the branded 419 page renders with the incident ID and recovery links, not the old generic page.
4. **Modal responsiveness**: check the modal renders correctly on a narrow (mobile-width) viewport.
5. **Copy/Reload/Close controls**: verify Copy Incident ID copies the correct value, Reload Page reloads only after the click (never automatically), and Close dismisses without reloading.
6. **Sign-in recovery**: after reload/close, confirm the user can navigate to login and sign in again normally.
7. **Transaction retry warning**: repeat step 2 on a Purchase/POS/Sale/Return/Stock/Payment/Receiving page and confirm the extra "verify before retrying" warning appears; repeat on a read-only page (e.g. a report) and confirm it does not appear.
8. **No native Livewire dialog**: across all Livewire scenarios above, confirm the browser's native `confirm()` dialog never appears.
