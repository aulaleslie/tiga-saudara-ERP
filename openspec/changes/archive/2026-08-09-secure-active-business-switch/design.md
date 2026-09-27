## Context

The header submits `POST /update-active-business` with a `setting_id`. The current controller immediately stores that value in session, refreshes the setting cache, optionally synchronizes the user's role, and redirects back to the referring URL. A prior-business URL can be invalid after switching because documents, POS sessions, locations, and other records are scoped by `session('setting_id')`.

The header only displays settings contained in `user_settings`, but the HTTP endpoint accepts a request independent of that UI. Login and the shared `settings()` helper establish the existing access model: Super Admins can access every Setting; other users can access only Settings connected through `user_setting`.

## Goals / Non-Goals

**Goals:**

- Make an active-business switch begin in an unscoped, valid landing page.
- Enforce the existing business-membership model at the server-side mutation boundary.
- Ensure denied requests leave the user's prior active context unchanged.
- Preserve Super Admin access to every existing business and existing role refresh behavior for standard users.

**Non-Goals:**

- Redesign business assignment, permissions, header presentation, or the `user_setting` schema.
- Repair every existing business-scoping inconsistency across unrelated endpoints.
- Change cross-business document selection rules or reuse that flow for active-session switching.
- Add a new API endpoint or alter the selector UI beyond its existing form submission.

## Decisions

### Authorize the target before mutating context

Resolve the requested Setting and determine accessibility before updating session, cache, or roles. A Super Admin is authorized for any existing Setting. Every other user must have a matching `user_setting` relationship.

This uses the active-business access policy already used at login, rather than trusting `user_settings` from session: the session can be stale after assignments change and is client-context state rather than the authoritative authorization source.

Alternative considered: validate only that the ID appears in session `user_settings`. Rejected because a stale session could retain revoked access.

Alternative considered: use `EffectiveDocumentBusinessResolver` directly. Rejected because it has document-specific override rules and treats the existing session setting as immediately allowed, which is not a sufficient authorization check at the active-context boundary.

### Use a uniform denial response

Return the application's normal authorization failure response for both nonexistent and inaccessible submitted IDs. Do not distinguish membership from existence in the response. Authorization is evaluated before all context side effects.

Alternative considered: redirect Home with a warning for failed switches. Rejected because it makes failed crafted requests appear successful and obscures the authorization contract; the normal selector never submits inaccessible IDs.

### Redirect successful switches to Home

After setting the authorized business context and refreshing role/cache state, redirect to named route `home`, not `back()`. Home is already an authenticated, active-business-aware landing route and has no previous document identifier that can become invalid.

Alternative considered: calculate a context-safe equivalent of the referring route. Rejected because route-specific safety rules would grow with the application and still leave forms, browser state, and filters from the previous business.

### Preserve existing session/cache/role responsibilities

The existing action remains the sole mutation point for `setting_id`, the `settings_{id}` cache entry, and the current per-setting role. The implementation will validate and load all required target data first, then perform the established refresh steps only for authorized requests.

## Risks / Trade-offs

- [A direct POST that previously switched an unassigned user will now fail] → This is an intentional security correction; the header only presents legitimate choices.
- [Super Admins can be unassigned from `user_setting`] → Preserve their documented all-business bypass and cover it with a regression test.
- [Users lose their previous page after a switch] → This is intentional to avoid stale scoped records, forms, and 404s; Home provides a known-good context start.
- [Session assignments may change during a browser session] → Query authoritative membership for each non-Super-Admin switch rather than relying on `user_settings`.

## Migration Plan

No database or data migration is required. Deploy the controller and tests as an application release. If rollback is required, revert the release; no persisted business data is changed by this feature.

## Open Questions

None. The established Super Admin all-business access model and Home route provide the required policy decisions.
