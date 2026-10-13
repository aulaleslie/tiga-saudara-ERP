# Design

## Context

See `proposal.md` for the incident. The expense approval route uses `IdempotencyMiddleware`. With no explicit token in either approval form, its fallback hashes route name, user ID, and POST body. The body is normally only `_token`, so distinct expense IDs in the URL collide for five minutes. The middleware redirects rejected requests with a session error; expense list and show screens do not render that error.

## Goals / Non-Goals

**Goals:**
- Keep the existing duplicate guard for a single expense while separating different expense targets.
- Make failed, uncommitted approval attempts retryable and explain duplicate rejection to the user.

**Non-Goals:**
- Change idempotency semantics for other routes.
- Change permissions, setting ownership, expense lifecycle rules, or the five-minute retention window.

## Decisions

1. **Add the expense route parameter to the approval claim identity.** Scope this adjustment to `expenses.approve` inside the existing middleware. Derive the same canonical token for claims and releases; keep the user and route scopes supplied by `IdempotencyService`. Direct POSTs and both browser forms then follow one rule. Adding hidden fields only to the forms was considered, but would leave tokenless POSTs with the collision.
2. **Release approval claims only after an uncommitted failure.** Keep a successful claim for the existing TTL so a double click cannot run approval twice. Make approval persistence transactional, with the submitted-status check and update protected against concurrent requests; on a thrown failure before commit, release the associated claim. A blanket release after every response was considered but would defeat duplicate protection. Keep the exception visible to the existing error handler.
3. **Render the middleware error on both expense entry points.** Show the `idempotency` session validation error on the index/list and show views with the existing alert styling. The redirect behavior can remain intact.

## Risks / Trade-offs

- **Two requests for the same expense race after cache expiry or across cache stores** → the transactional status check must still allow only one state transition; the second request receives a clear lifecycle rejection.
- **A notification failure occurs after status persistence** → keep status and notification work within the approval transaction, or otherwise distinguish committed success from failed pre-commit work before releasing the claim. Do not make a committed approval retryable as if it were uncommitted.
- **Shared middleware change affects other routes** → branch only for `expenses.approve` and cover the default behavior with a focused regression check.

## Migration Plan

No schema or data migration. Deploy the code change; existing cache claims expire within five minutes. Rollback restores previous behavior without data conversion.
