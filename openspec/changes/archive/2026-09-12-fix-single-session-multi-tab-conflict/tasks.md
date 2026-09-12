## 1. Root-cause confirmation

- [x] 1.1 Write a failing regression test that reproduces the false-positive path: two rapid/concurrent authenticated requests from the same login (same session-derived identity) must not result in the earlier session being destroyed.
- [x] 1.2 Write a test confirming current (pre-fix) behavior still correctly invalidates a genuinely different login for the same user, to lock in the behavior that must be preserved.

## 2. Middleware fix

- [x] 2.1 Introduce a stable per-login identity (e.g. a token generated at authentication and stored in session data) distinct from the raw session ID.
- [x] 2.2 Update `SingleSessionMiddleware::handle()` to compare the per-login identity instead of (or in addition to) raw session ID equality when deciding whether a conflict exists.
- [x] 2.3 Guard the read-check-destroy-write sequence against races (e.g. `Cache::lock`) so concurrent requests from the same login cannot each conclude a conflict exists.
- [x] 2.4 Ensure the genuine-second-login path still destroys the prior session and still emits `single_session_observed`/`single_session_invalidated` with fingerprinted identifiers only.

## 3. Verification

- [x] 3.1 Run the tests from 1.1 and 1.2 against the fixed middleware; both must pass.
- [x] 3.2 Add/extend feature tests covering: same-login multi-tab (no invalidation), genuine second login (invalidation still occurs), and that `csrf_mismatch`/419 no longer follows a same-login multi-tab sequence.
- [x] 3.3 Run full Purchase module and session/middleware test suites (`composer test:fresh-sqlite` or filtered `php artisan test`) to confirm no regressions.

## 4. Rollout confirmation

- [x] 4.1 After deploy, confirm via `single_session_invalidated`/`csrf_mismatch` log correlation that same-login multi-tab sequences (as seen for user 10 around incident `ERP-TDSR4YDTML`) no longer produce invalidation-then-419 sequences.
