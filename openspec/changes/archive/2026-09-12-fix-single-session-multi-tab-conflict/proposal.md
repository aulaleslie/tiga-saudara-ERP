## Why

Production incident `ERP-TDSR4YDTML` (2026-09-12, user 10, `purchases.create`) and repeated prior 419s share a pattern: they occur while a single legitimate user has the ERP open, most visibly on long-lived Purchase create/edit forms, and can surface even when the user is idle or performing their very first action on the page. `openspec/changes/archive/2026-09-09-instrument-session-419-errors` added the diagnostics that made this pattern observable and explicitly deferred any change to single-session policy pending evidence. That evidence now exists in `single_session_observed`/`single_session_invalidated` events immediately preceding `csrf_mismatch` events for the same user, with no genuine second device or account-sharing involved (confirmed: affected users work from one browser, multiple tabs).

`SingleSessionMiddleware` compares the current request's session ID against a single cached "last seen" session ID per user and immediately destroys whatever session it finds cached under a different ID, on every authenticated request, with no grace window and no way to distinguish a second legitimate tab from a genuine second device. Combined with the `file` session/cache driver, this produces false-positive invalidations of a user's own still-open tab, which then 419s on its next request — regardless of what that request is, which explains why the failure has no fixed trigger action.

## What Changes

- Change `SingleSessionMiddleware`'s conflict detection so that legitimate concurrent requests from the same authenticated user's own browser (multiple tabs/windows sharing one session cookie) do not destroy a session that is still in active use.
- Preserve the product intent of single-session enforcement (one *device/login* per user) without punishing ordinary multi-tab usage of the same login.
- Add regression coverage that reproduces the false-positive path (concurrent/rapid same-session requests) and asserts the previously-observed session is not destroyed, alongside coverage that a genuine second login still invalidates the first.
- Extend existing `single_session_*` diagnostics only as needed to confirm the fix in production without reintroducing the deferred-and-now-resolved ambiguity.

## Capabilities

### Modified Capabilities

- `single-session-enforcement`: Correct false-positive session invalidation under legitimate same-user, multi-tab/concurrent-request usage while preserving genuine single-device-per-login enforcement.

## Impact

- Affects `app/Http/Middleware/SingleSessionMiddleware.php` and its cache-based conflict-detection contract; no database schema changes.
- Interacts with `session-expiry-observability` (uses its diagnostics service and event names; does not change the 419/incident-ID response contract).
- Directly resolves the root cause behind incident `ERP-TDSR4YDTML` and the broader class of "spontaneous" 419s on Purchase create/edit.
- Does not change session lifetime, session driver, or CSRF validation itself.
