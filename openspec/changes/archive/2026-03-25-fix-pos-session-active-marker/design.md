## Context

Currently, the POS application uses an `active_marker` column on the `pos_sessions` table to enforce uniqueness for active sessions and quickly filter active sessions (via `scopeActive`). A session is considered active if `active_marker` is not null.

When a cashier closes a session normally, the system updates the session status to `CLOSED` and properly relies on `activeMarkerForStatus()` to set `active_marker` to `null`. However, two other backend flows—Admin Force Close (`PosSessionAdminCloseService`) and Supervisor Finalization (`PosSessionFinalizeService`)—update the session status directly to `CLOSED` or `FINALIZED` without updating `active_marker`.

This leaves the `active_marker` set to `1` in the database, breaking the `getActiveSessionForCashier()` lookup. Consequently, when a cashier navigates to `/pos/sell`, the `EnsureActivePosSessionMiddleware` falsely detects an active session and allows the user into the POS UI with a finalized session, instead of redirecting them to the "Open Session" screen.

## Goals / Non-Goals

**Goals:**
- Ensure `active_marker` is correctly nullified when a session transitions to `CLOSED` via Admin Force Close.
- Ensure `active_marker` is correctly nullified when a session transitions to `FINALIZED` via Supervisor Finalization.
- Restore correct middleware behavior for `/pos/sell`.

**Non-Goals:**
- Refactoring the POS session architecture or how `active_marker` uniqueness is built.

## Decisions

- **Use Existing activeMarkerForStatus Helper:** We will modify `PosSessionAdminCloseService` and `PosSessionFinalizeService` to include `'active_marker' => PosSession::activeMarkerForStatus(...)` during their `update()` calls.
  - *Rationale:* This provides a DRY and consistent way to determine the correct marker without hardcoding `null`.

## Risks / Trade-offs

- **Risk:** Existing finalized sessions in the database currently have `active_marker = 1`, causing immediate issues for cashiers right now.
  - *Mitigation:* While this code change prevents the issue moving forward, a manual DB cleanup (`UPDATE pos_sessions SET active_marker = NULL WHERE status IN ('CLOSED', 'FINALIZED')`) might be needed by the user.

