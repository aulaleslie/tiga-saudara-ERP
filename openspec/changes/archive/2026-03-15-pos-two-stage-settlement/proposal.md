## Why

Currently, POS terminal sessions must remain open until a cashier completes cash counting and reconciliation, blocking the terminal from use by other cashiers even when transactions are complete. This creates bottlenecks during busy periods. Additionally, the cash reconciliation and variance approval process needs clear separation between terminal release (immediate) and cash settlement (delayed until supervisor handoff).

## What Changes

- **Admin Force-Close**: Privileged users (with `pos.sessions.close-admin` permission) can immediately close OPEN terminals without waiting for the cashier to count cash, freeing the terminal for other cashiers.
- **Two-Stage Settlement**: Separate terminal closure (OPEN → CLOSED) from cash finalization (CLOSED → FINALIZED). Terminal becomes available after CLOSE, but cash settlement happens during FINALIZE.
- **Supervisor Cash Finalization**: Supervisors (with `pos.supervisor.approval` permission) receive cash from cashiers and enter the actual amount received, triggering variance calculation and approval workflows.
- **Variance Approval Gate**: New permission `pos.sessions.approve-variance` controls who can approve cash variances during finalization, distinct from general supervisor approval.
- **Session Status Extension**: Add `FINALIZED` status to represent completed settlement (currently: OPEN, CLOSING, CLOSED → will add FINALIZED).
- **Enhanced Session Index UI**: Add action buttons for admin force-close and supervisor finalization with modal forms showing full session reconciliation details.

## Capabilities

### New Capabilities

- `pos-admin-force-close`: Admin can force-close OPEN terminals immediately to release them for reuse, recording the force-close action with admin user ID.
- `pos-supervisor-cash-finalization`: Supervisor receives cash and enters actual amount received; system calculates expected cash from session data and variance. Triggers approval workflow if variance exceeds threshold.
- `pos-session-variance-approval`: Permission-based approval of cash variances during finalization. New permission `pos.sessions.approve-variance` controls escalation when variance > threshold.

### Modified Capabilities

- `pos-session-lifecycle`: Changed from 3-state (OPEN → CLOSING → CLOSED) to 4-state (OPEN → CLOSED → FINALIZED), with two separate close paths: normal cashier close and admin force-close. Variance approval now gates transition to FINALIZED instead of to CLOSED.

## Impact

- **New Permissions**: `pos.sessions.close-admin`, `pos.sessions.approve-variance`
- **New Database Status**: `FINALIZED` session status constant
- **New Services**: `PosSessionAdminCloseService`, `PosSessionFinalizeService`
- **New Routes**: `POST /pos/sessions/{session}/close-admin`, `POST /pos/sessions/{session}/finalize`
- **Modified Routes**: `/pos/sessions` index view (add action buttons)
- **Database Schema**: Potential metadata column additions to track close type and finalization context
- **Testing**: New feature tests for both force-close and finalization flows with variance approval scenarios
