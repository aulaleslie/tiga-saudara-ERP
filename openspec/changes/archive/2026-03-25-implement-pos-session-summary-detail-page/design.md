## Context

Currently, `GET /pos/sessions/{id}/summary` in `PosSessionController` returns JSON via `PosSessionSummaryService`. No view exists to display this data. When users click the "Detail" button on the sessions list, they see raw JSON in the browser instead of a formatted page. The service already calculates expected cash, loads transaction history, and builds the cash event timeline—we're only missing the presentation layer.

The proposal commits to creating a full-page view with four sections: session overview, cash events timeline (with filtering), transaction ledger (with drillable details), and action buttons (reusing existing modals).

## Goals / Non-Goals

**Goals:**
- Serve a properly formatted Blade view instead of raw JSON from the summary endpoint
- Display session metadata (terminal, cashier, status, timestamps) prominently
- Show all cash events in reverse chronological order with filtering by event type
- Display last 50 transactions in a ledger view; allow drilling down to full checkout details via modal
- Include conditional action buttons (close, finalize) that leverage existing modal handlers
- Maintain authorization: owner views own session, `pos.sessions.view` permission views any

**Non-Goals:**
- Modifying `PosSessionSummaryService` (reuse as-is)
- Creating new API endpoints (repurpose existing summary endpoint)
- Changing auth logic (keep existing checks)
- Building a new checkout detail view (reuse or build minimal modal)

## Decisions

### Decision 1: Endpoint Response Type
**Choice**: Convert `summary()` from `return response()->json()` to `return view()`

**Rationale**: The endpoint URL and authorization are already correct; we're only adding presentation. Returning a view is the standard Laravel pattern for detail pages.

**Alternative considered**: Create a separate endpoint (`/pos/sessions/{id}/detail`) that returns HTML. Rejected because we already have a well-named endpoint and URL. Also avoids duplicating authorization logic.

---

### Decision 2: View File Location and Data Flow
**Choice**: Create `Modules/Pos/Resources/views/session/summary.blade.php`. Controller passes service response array directly to view.

**Rationale**: Follows Laravel convention (views in `resources/views/<module>`). Service returns a structured array; view consumes it directly without transformation.

**Alternative considered**: Create a wrapper ViewModel or Response class. Rejected because the service already returns a well-structured array with clear keys; additional abstraction adds complexity.

---

### Decision 3: Cash Events Filtering
**Choice**: Implement client-side filtering with JavaScript buttons. All events loaded on page; filtering toggles CSS visibility/display.

**Rationale**: Simplest for 50-200 events (typical session size). No extra backend call needed. Fast user experience.

**Alternative considered**: Server-side filtering via query params (e.g., `?event_type=SAFE_DROP_OUT`). Rejected because we'd need to modify the service and controller; overkill for a single detail page where the event list is usually small.

---

### Decision 4: Transaction Detail Drill-down
**Choice**: Clicking a transaction row opens a Bootstrap modal showing full checkout details (not a separate page).

**Rationale**: User stays on the session detail page. Modal is quicker and keeps context. Avoids creating a new checkout detail view (existing handlers show this is the pattern).

**Alternative considered**: Link to a full checkout detail page. Rejected because navigation away loses session context and back-button UX is poor.

---

### Decision 5: Action Buttons (Close/Finalize)
**Choice**: Reuse existing `closeAdminModal` and `finalizeModal` from the sessions list page. Include data attributes on buttons; JavaScript event handlers fetch session data and populate modals.

**Rationale**: Modals already exist and work. JavaScript handlers in `pos-session-handlers.js` already know how to populate them. Avoids duplication.

**Alternative considered**: Build new modals on the summary page. Rejected because existing ones are tested and in use; duplicating would create maintenance burden.

---

### Decision 6: Layout and Sections
**Choice**: Single page with four stacked sections:
1. **Session Overview** (summary card at top)
2. **Cash Events Timeline** (with filter buttons)
3. **Transactions Ledger** (table with click-to-detail)
4. **Action Buttons** (bottom, conditional on status/permissions)

**Rationale**: Logical flow from high-level overview → detailed timeline → transaction history → actions. Matches the pattern in `transactions/show.blade.php`.

---

## Risks / Trade-offs

**[Risk]** Cash events list could be very long (100+ events in edge cases) → **Mitigation**: Current service loads all; if performance becomes an issue, paginate server-side later. For now, accept the load.

**[Risk]** Existing modal handlers in `pos-session-handlers.js` assume modals are in the DOM → **Mitigation**: Include modal HTML in the view (or import via include). Test that modals still populate correctly.

**[Risk]** Checkout detail modal doesn't exist yet → **Mitigation**: Build a minimal modal fetching checkout data from the backend. Or link to existing checkout detail page if one exists. Clarify during implementation.

**[Trade-off]** Client-side filtering is fast but doesn't persist (reload loses filter state) → **Accepted**: Detail pages are typically viewed once; session reloads are rare. Not worth server-side complexity.

## Migration Plan

**Deployment:**
1. Create new view file `session/summary.blade.php`
2. Modify `PosSessionController::summary()` to render view
3. Add JavaScript handler for modal interactions (if modals are in the detail view)
4. Test that Detail links now show formatted page, not JSON

**Rollback:**
- Revert `PosSessionController::summary()` to `return response()->json()`
- Delete `session/summary.blade.php`

**No database migrations or breaking changes.**

## Open Questions

1. **Checkout detail modal**: Should we build a new modal on the summary page, or does one exist elsewhere? If new, what fields to display (receipt#, items, amounts, payment method, approvals)?
2. **Cash events edge case**: If a session has 500+ cash events, should we paginate or load all? Current service returns all.
3. **Date/time format**: Use the locale-aware helpers already in the codebase (e.g., `format_currency`, `formatDateTime`)? Check existing patterns.
