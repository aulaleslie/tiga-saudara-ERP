## Context

Currently, the POS session close functionality is split across two separate code paths:

1. **Standard Close** (`/pos/sessions/{id}/close`):
   - Uses `PosSessionCloseService`
   - Requires `pos.sessions.close` permission
   - Only allows cashier to close their own session
   - Flow: OPEN → CLOSING → CLOSED

2. **Admin Force-Close** (`/pos/sessions/{id}/close-admin`):
   - Uses `PosSessionAdminCloseService`
   - Requires `pos.sessions.close-admin` permission
   - Allows admin to close any session
   - Flow: OPEN → CLOSED (bypasses CLOSING)

The summary page has two separate buttons, modals, and JavaScript handlers for these flows. Users must understand which button to use based on their permissions. The business logic for "who can close what" is scattered across permissions, controller checks, and service-level authorization.

## Goals / Non-Goals

**Goals:**
- Consolidate both close operations into a single endpoint (`POST /pos/sessions/{id}/close`)
- Centralize authorization logic in one controller method
- Simplify UI by showing one "Tutup Sesi" button regardless of permission level
- Maintain backward compatibility: both `pos.sessions.close` and `pos.sessions.close-admin` permissions continue to work as before
- Keep business logic clear: the endpoint determines close type based on user permissions and session ownership

**Non-Goals:**
- Change the underlying close flows (OPEN → CLOSING → CLOSED vs OPEN → CLOSED still exist internally)
- Modify permission names or semantics
- Alter cash event creation or audit trail requirements
- Change the behavior of either close type (both continue to work exactly as they do now)

## Decisions

### Decision 1: Single Endpoint, Conditional Service Dispatch

**Choice:** Merge both endpoints into one `POST /pos/sessions/{id}/close` that internally decides which service to call.

**Rationale:**
- Provides a single API contract for all session closes
- Authorization logic is centralized in one place (the controller method)
- The decision of "standard vs admin close" is made once, based on permissions and session ownership
- Simplifies client-side code (one button, one modal, one form submission)

**Alternative considered:** Keep both endpoints but make them both POST to `/pos/sessions/{id}/close` with a parameter to distinguish them.
- Rejected: Still requires clients to know the difference and pass the parameter; adds complexity

### Decision 2: Authorization in Controller, Dispatch to Service

**Choice:** Controller checks permissions and session ownership, then calls the appropriate service.

**Authorization Logic:**
```
if user has pos.sessions.close-admin:
  → Proceed with admin close (any session)
elif user has pos.sessions.close:
  → Check if session belongs to user
  → If yes: proceed with standard close
  → If no: deny with 403
else:
  → Deny with 403
```

**Rationale:**
- Keeps authorization logic in one place (the controller method)
- Both services remain unchanged (minimal refactoring)
- Clear separation: controller decides authorization, services execute the close
- Easy to understand: read one method to understand who can close what

**Alternative considered:** Merge both services into one with a parameter.
- Rejected: Adds complexity to service layer; harder to maintain distinct OPEN→CLOSING→CLOSED vs OPEN→CLOSED flows

### Decision 3: Single Modal, Backend Determines Close Type

**Choice:** Summary page shows one "Tutup Sesi" button and one modal (`#closeModal`). Backend determines if it's standard or admin close based on user permissions.

**Rationale:**
- Simplest UI (one button, one modal, one form)
- No client-side logic needed to detect permission and show different UI
- If user's permissions change between page load and form submission, the backend validates on submission anyway

**Alternative considered:** Keep two buttons, remove `_close-admin-modal.blade.php`.
- Rejected: Adds more conditional rendering in the blade template; still requires explaining why there are two buttons

### Decision 4: Remove `/pos/sessions/{id}/close-admin` Route Entirely

**Choice:** Delete the `close-admin` route and POST endpoint from routes/web.php. Consolidate into `close` route.

**Rationale:**
- Fewer routes = simpler routing table
- No ambiguity about which endpoint to use
- `close-admin` was only available on summary page anyway (not referenced elsewhere)

**Alternative considered:** Keep both routes, make them both work.
- Rejected: Adds unnecessary duplication; clients must still choose which one to call

## Risks / Trade-offs

**[Risk] Breaking change for external API consumers**
- Mitigation: If external systems call `/pos/sessions/{id}/close-admin`, they will get 404. Recommend checking codebase for API usage first. If needed, add a deprecation period where both routes work.

**[Risk] Session ownership check happens twice if user lacks `pos.sessions.close-admin`**
- Mitigation: Accept this minor inefficiency. It's one database query and improves code clarity. Caching user permissions is possible but adds complexity.

**[Risk] If permissions change between page load and form submission, user may see unexpected errors**
- Mitigation: This is acceptable. Backend validation always happens; frontend rendering is just an optimization. Error message explains what happened.

**[Trade-off] Code paths `OPEN→CLOSING→CLOSED` and `OPEN→CLOSED` remain separate**
- Rationale: Separating them into different services makes the distinction clear and avoids introducing bugs in already-working code. The API unification happens at the endpoint level; internal flows can remain distinct.
