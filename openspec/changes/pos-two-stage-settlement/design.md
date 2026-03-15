## Context

Current POS session workflow is linear: OPEN → CLOSING → CLOSED. Cashiers count cash, enter amounts, system calculates variance, and only then closes the session. This locks the terminal until cash reconciliation completes, creating bottlenecks.

The new workflow must support:
1. **Immediate terminal release** via admin force-close (allows other cashiers to use the terminal)
2. **Delayed cash settlement** via supervisor finalization (happens later when supervisor receives cash from cashier)
3. **Variance approval** at the finalization stage, not the close stage

## Goals / Non-Goals

**Goals:**
- Enable admin users to force-close OPEN terminals immediately, releasing them for reuse
- Support supervisor cash finalization with manual cash entry and variance calculation
- Implement permission-based variance approval distinct from general supervisor approval
- Track which user closed/finalized sessions and in what role (admin vs cashier)
- Display rich session reconciliation details (sales breakdown, expected vs actual cash) in finalization modal
- Maintain audit trail of all session close/finalize actions

**Non-Goals:**
- Modify existing cashier close flow (OPEN → CLOSING → CLOSED remains unchanged)
- Auto-finalize sessions (all CLOSED sessions must be manually finalized by supervisor)
- Change PosCheckout or payment method models
- Implement multi-currency or batched finalization

## Decisions

### 1. Session Status States: OPEN → CLOSED → FINALIZED (not CLOSING)

**Decision**: Add `FINALIZED` status constant. Admin force-close transitions OPEN directly to CLOSED (skipping CLOSING state). Cashier normal close uses existing OPENING → CLOSING → CLOSED path.

**Rationale**:
- Admin force-close should be immediate (no intermediate state)
- Finalization is supervisor concern, not part of normal close flow
- Two separate paths (admin vs cashier) can converge at CLOSED, then proceed to FINALIZED

**Alternatives Considered**:
- All closes go through CLOSING: Would delay admin force-close, defeating purpose
- FINALIZED only for admin closures: Would complicate logic; FINALIZED should be universal end state

### 2. Variance Approval: New Permission `pos.sessions.approve-variance`

**Decision**: Create distinct permission from `pos.supervisor.approval`. Admin with both `pos.sessions.close-admin` AND `pos.sessions.approve-variance` can approve variances when finalizing.

**Rationale**:
- Separates concerns: supervisor approval (e.g., cart overrides) vs variance approval (cash settlement)
- Allows granular control: admin might close terminals but supervisor approves variances
- Enables store owner to be sole variance approver without other supervisor duties

**Alternatives Considered**:
- Reuse `pos.supervisor.approval`: Would conflate unrelated approvals
- No approval for admin actions: Violates audit requirement; variances must be reviewed

### 3. Expected Cash Calculation: `opening_float + cash_sales - safe_drops`

**Decision**: Expected cash = session's opening_float + sum of checkouts with cash payment methods - total safe drops (OUT direction events).

**Rationale**:
- opening_float: Initial cash in drawer
- cash_sales: Sum of all checkout grand_totals where payment_method.is_cash = true
- safe_drops: Already subtracted from expected_cash_total during session
- Non-cash payments (card, transfer, etc.) don't affect cash drawer

**Alternatives Considered**:
- Include non-cash as credit: Would complicate reconciliation; supervisor receives cash, not promises
- Recalculate from PosSessionCashEvent: Already done by PosSessionExpectedCashCalculator; reuse it

### 4. Services: PosSessionAdminCloseService + PosSessionFinalizeService

**Decision**:
- `PosSessionAdminCloseService`: Handles force-close (OPEN → CLOSED), records admin user, creates close event
- `PosSessionFinalizeService`: Handles finalization (CLOSED → FINALIZED), calculates variance, gates on approval

**Rationale**:
- Separates concerns: admin close vs supervisor finalize
- Admin close is simple (no calculations); finalize is complex (variance, approval)
- Easier to test and maintain as separate services

**Alternatives Considered**:
- Single service for both: Would be ~300+ lines with branching logic
- Extend PosSessionCloseService: Would break existing close flow; safer to create new service

### 5. Routes and Permissions

**Decision**:
- `POST /pos/sessions/{session}/close-admin` requires `can:pos.sessions.close-admin`
- `POST /pos/sessions/{session}/finalize` requires `can:pos.supervisor.approval`
  - If variance > threshold: Also requires `can:pos.sessions.approve-variance` OR user can bypass if admin
- UI buttons in `/pos/sessions` index show/hide based on session status and user permissions

**Rationale**:
- Middleware checks happen first; easier to understand permission flow
- Admin force-close is distinct from normal close route
- Finalize requires supervisor approval permission (already used for cart overrides, etc.)

### 6. Modal Form: Full Reconciliation Details

**Decision**: Finalization modal shows:
- Session header (terminal, cashier, opened_at, duration)
- Sales summary (total sales, cash sales, non-cash sales)
- Expected cash breakdown (opening float + cash sales - safe drops)
- Safe drop summary if any
- INPUT: "Actual Cash Received" (currency field)
- Display calculated variance with color (red if exceeds threshold)
- Denomination breakdown (optional, hidden by default)
- Submit/Cancel buttons

**Rationale**:
- Supervisor sees full context to make informed decision
- Transparent calculation builds confidence
- Matches existing session detail pages style
- Input field is simple (single amount, not denominations)

### 7. Metadata Tracking

**Decision**: Add to PosSession.metadata:
- `closed_by_role`: 'cashier' | 'admin' (indicates which path was taken)
- `admin_close_reason`: Optional text if admin force-closes (for audit)

**Rationale**:
- Audit trail: distinguish admin force-closes from normal cashier closes
- No new table columns needed; metadata is flexible
- Minimal storage impact

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| **Admin abuse**: Admin force-closes session while cashier still selling | Logged with admin user ID; audit trail enables investigation. Consider adding warning banner when admin initiates close. |
| **Supervisor misses finalization**: Many CLOSED sessions pile up | Dashboard/report showing pending CLOSED sessions. Email reminder (future work). |
| **Variance calculation incorrect**: If safe_drops data is inconsistent | Use existing `PosSessionExpectedCashCalculator` which is battle-tested. Reconciliation table audit (future). |
| **Permission confusion**: Admins don't realize they need BOTH close-admin AND approve-variance | Documentation + permission descriptions clear ("Grant this to allow variance approval for cash settlement"). |
| **UI clutter**: Too many buttons in session index | Use dropdown menu for admin actions; keep Finalize as primary action for supervisors. |

## Migration Plan

1. **Database**: Add `FINALIZED` status constant (no migration needed; it's a code constant)
2. **Permissions**: Add `pos.sessions.close-admin` and `pos.sessions.approve-variance` via Permissions seeder
3. **Services**: Create `PosSessionAdminCloseService` and `PosSessionFinalizeService`
4. **Routes**: Add two new POST routes with proper middleware
5. **UI**: Update `/pos/sessions` index view to show conditional action buttons
6. **Tests**: Add feature tests for both paths (admin close, supervisor finalize with/without approval)
7. **Deploy**: No breaking changes; old close flow remains. New permissions default to false for all roles except those explicitly granted.

**Rollback**: Remove new routes, hide UI buttons, revoke new permissions. Existing CLOSED sessions can remain; they're benign (won't auto-finalize).

## Open Questions

- Should admin force-close require a reason/note field for audit purposes? (Recommended: add optional text field)
- Should finalization send notification to cashier confirming settlement? (Deferred: future notification system)
- Should we auto-finalize after X days of CLOSED state? (No: explicit supervisor action required for accountability)
