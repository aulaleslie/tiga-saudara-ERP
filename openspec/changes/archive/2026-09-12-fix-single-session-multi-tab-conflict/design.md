## Context

`SingleSessionMiddleware` runs on every authenticated request and implements a simple "last writer wins" policy: it stores one session ID per user in cache (`user_session_{id}`, 2h TTL) and, whenever the current request's session ID differs from the cached one, immediately destroys the cached (older) session before overwriting it with the current one.

This assumes a session ID uniquely and stably identifies "one login." In practice, on this codebase, the `file` session/cache driver combined with concurrent requests from the same browser (multiple tabs sharing one cookie) makes that assumption unreliable: overlapping requests can race on read/write of the cached session ID and of the session file itself, so the middleware can observe what looks like "a different session" for the same login and destroy a session that is still actively backing an open tab. The destroyed session's tab then 419s on whatever its next request happens to be, which is why the failure has no fixed trigger (idle, search, or any field edit) and shows up most on Purchase create/edit — the page users tend to leave open longest alongside other tabs.

Production evidence (incident `ERP-TDSR4YDTML`) shows exactly this: `single_session_invalidated` firing for user 10 three times within four seconds, followed by a `csrf_mismatch` on `purchases.create`, with the affected user confirmed to be working from one browser with multiple tabs, not a second device.

## Goals / Non-Goals

**Goals:**

- Stop the middleware from destroying a session that is still the legitimate, currently-in-use session for that user (i.e., eliminate the false-positive multi-tab case).
- Preserve genuine single-device/single-login enforcement: a real second login (different browser, different device, or explicit re-authentication) must still invalidate the first.
- Keep the fix small and local to the middleware's conflict-detection logic; avoid a session-driver migration (e.g. to Redis) as a precondition, though note it as an option if the minimal fix proves insufficient.
- Keep existing `single_session_observed`/`single_session_invalidated` diagnostic events meaningful so the fix's effect is verifiable in production logs.

**Non-Goals:**

- Changing `SESSION_DRIVER`/`CACHE_DRIVER` away from `file` as part of this change (may be recommended separately if races persist).
- Changing session lifetime, CSRF token handling, or the 419 incident/response contract from `session-expiry-observability`.
- Building a full multi-device session management UI (e.g. "log out other devices" list) — out of scope.

## Decisions

### 1. Distinguish "same browser, new tab" from "different login" without trusting a single mutable cache key

Root cause is that the middleware's signal (`session()->getId()` vs. one cached ID) cannot tell these apart under concurrency, because both a same-browser second tab and a genuine second device can transiently present a "different" session ID to a racing read. The fix needs a signal that is stable across tabs of the same login but changes on a genuine new login.

Candidate approaches to evaluate during implementation:

- **Key the cached identity to the authentication event, not the session ID.** Store a stable per-login token (e.g. generated once at login and stored in the session itself) alongside the session ID. Only treat it as a conflict when the cached *login token* differs — same-browser tabs share the same session data (and thus the same login token) even if the low-level session ID briefly disagrees due to a driver race, while a genuine second login mints a new login token.
- **Add a short grace/debounce window** before destroying a "conflicting" session, re-checking after a brief delay to rule out a transient race, rather than destroying synchronously on first observation.
- **Serialize the read-modify-write** (compare cached ID, decide, destroy, overwrite) under a lock (e.g. `Cache::lock`) so concurrent requests from the same login don't race each other into contradictory conclusions.

These are not mutually exclusive; the login-token approach directly fixes the root ambiguity (it stops relying on session-ID equality as a proxy for "same login") and is the primary candidate, with locking as a complementary safeguard against write races on the cache key itself.

Alternative considered: switching the cache/session driver to Redis to eliminate file-driver races. Rejected as the primary fix because it doesn't address the deeper issue — even with a race-free store, comparing raw session IDs still cannot distinguish "same login, second tab, ID rotated for an unrelated reason" from "different login," so the middleware would remain fragile; a driver change may still be worth doing separately for other reasons.

### 2. Preserve enforcement intent: still kill a real second device/login

Whatever mechanism is chosen must still invalidate the previous session when a *different* login event occurs for the same user (the original feature's purpose). The regression test suite must cover both directions explicitly: same-login multi-tab must NOT invalidate; a fresh login (new login token) MUST invalidate the prior one.

### 3. Diagnostics stay in place, extended only if needed to prove the fix

`single_session_observed`/`single_session_invalidated` remain the events used to confirm behavior in production. If the chosen mechanism introduces a new comparison key (e.g. login token), the diagnostic payload may need an additional fingerprinted field to make the new distinction visible in logs, following the same one-way-fingerprint, no-raw-secrets rule established in `session-expiry-observability`.

## Risks / Trade-offs

- **Under-invalidation risk:** loosening the check could theoretically let a genuinely stale/compromised session linger longer than before. Mitigated by keeping the login-token comparison strict (still destroys on any real new login) and by not removing the underlying destroy-and-cache mechanism, only correcting what counts as "different."
- **Session payload dependency:** storing a login token inside the session ties correctness to session data surviving across requests, which is the same data store already relied upon; this is not a new failure mode.
- **Residual file-driver races:** if races persist even after fixing the comparison signal, a follow-up to move to a shared, atomic cache/session store (e.g. Redis) may still be warranted; this change does not block that option.

## Migration Plan

No data migration. Existing cached `user_session_{id}` keys naturally expire (2h TTL) and are superseded by whatever new key/shape is introduced; no backfill needed. Deploy as a normal code change behind existing test coverage.

## Open Questions

- Should the login-token comparison also account for explicit "sign out other sessions" as a future feature, or is that out of scope entirely for now? (Leaning: out of scope, note as a possible future capability.)
