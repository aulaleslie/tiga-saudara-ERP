## Context

The POS session lifecycle was redesigned for two-stage settlement: immediate terminal release (close) followed by delayed cash reconciliation (finalize). However, the cashier close endpoint (`POST /pos/sessions/{id}/close`) retained variance calculation and supervisor approval logic from the original one-stage workflow. This creates a mismatch: the finalize service correctly handles variance, but the close service also tries to, blocking cashiers with confusing error messages about supervisor approval during what should be a simple terminal-release action.

Currently, three close paths exist:
1. **Cashier close** (`/pos/sessions/{id}/close`) — incorrectly calculates variance and requires approval
2. **Admin force-close** (`/pos/sessions/{id}/close-admin`) — correctly releases terminal immediately
3. **Supervisor finalize** (`/pos/sessions/{id}/finalize`) — correctly handles variance and approval

This change aligns the cashier close path with the design intent.

## Goals / Non-Goals

**Goals:**
- Remove all variance calculation, cash counting, and supervisor approval logic from `PosSessionCloseService`
- Simplify close action to pure terminal release: transition OPEN/CLOSING → CLOSED, optionally record reason
- Update `StorePosSessionCloseRequest` to accept only `reason` (optional)
- Simplify `PosSessionController::closeFinalize()` accordingly
- Remove the close modal from the sell page; replace with simple confirmation or direct action
- Ensure existing finalize flow remains unchanged (all variance handling stays in finalize)
- Maintain backward compatibility at the route level (same endpoint, simpler contract)

**Non-Goals:**
- Change `PosSessionFinalizeService` (already correct)
- Change admin force-close or any other session endpoints
- Modify database schema or add migrations
- Change permission model (existing `pos.sessions.close` permission still applies)
- Refactor the sell page beyond removing the close modal

## Decisions

### 1. Close Signature: Remove Cash and Supervisor Parameters

**Decision**: Change `closeSession()` signature from:
```php
closeSession(int $settingId, int $sessionId, int $actorUserId, float $countedCashTotal,
            ?array $countedDenominations, ?string $notes,
            ?string $supervisorIdentifier, ?string $supervisorPin)
```
to:
```php
closeSession(int $settingId, int $sessionId, int $actorUserId, ?string $reason = null)
```

**Rationale:**
- Variance approval is now the supervisor's responsibility during finalize, not the cashier's during close
- No need to count cash during close if there's no variance approval at this stage
- Smaller parameter set = simpler, clearer intent (release terminal)

**Alternatives Considered:**
- Keep parameters but ignore them: Confusing API contract; better to remove
- Make them optional but still try to calculate variance: Mixing concerns; defeats purpose of simplification

### 2. Close Behavior: Simple Transition with No Calculations

**Decision**: `closeSession()` should:
1. Validate session exists and user is assigned to setting
2. Lock session for update
3. If status is OPEN, transition to CLOSING first (existing behavior via `sessionLifecycleService.startClosing()`)
4. If status is CLOSING or OPEN, transition directly to CLOSED (via `sessionLifecycleService.finalizeClosing()`)
5. Optionally record reason in metadata or notes
6. Return success response with session ID, status, and closed_at timestamp
7. No variance calculation, no conditional approval logic

**Rationale:**
- Mirrors admin force-close simplicity: transition happens, done
- Cashier doesn't need variance details at close time (supervisor will see them during finalize)
- No supervisor involvement needed; removes blocking behavior

**Alternatives Considered:**
- Keep variance calculation but skip approval: Still complicates close; variance belongs in finalize
- Create separate "force release" endpoint for cashiers: Unnecessary; close endpoint now does this

### 3. Request Validation: Only Accept Reason

**Decision**: `StorePosSessionCloseRequest` rules:
```php
return [
    'reason' => ['nullable', 'string', 'max:500'],
];
```

Remove validation for:
- `counted_cash_total`
- `counted_denominations`
- `supervisor_identifier`
- `supervisor_pin`

**Rationale:**
- Prevents accidental submission of data that service no longer processes
- Clear API contract: close endpoint doesn't want cash or supervisor data

### 4. Controller: Simplify Error Handling

**Decision**: In `PosSessionController::closeFinalize()`:
- Remove all variance-related error handling (no longer applicable)
- Keep only: authentication check, setting validation, session existence check, exception handling
- Remove the "blocked" response path (service will no longer return it)

**Rationale:**
- Service no longer blocks on variance, so controller doesn't need to handle it
- Simpler, fewer code paths

### 5. Frontend: Remove Close Modal, Simplify UX

**Decision**:
- Delete or hide the close session modal from sell.blade.php
- Replace with one of:
  - Option A: Simple confirmation dialog ("Are you sure?") with OK/Cancel
  - Option B: Direct button click with toast feedback (no confirmation)
- Remove all cash counting and supervisor credential input from close flow

**Rationale:**
- If close is just "release terminal," no need to collect cash data
- Reason field is optional; not required in modal
- Simplifies user experience: fewer clicks to close

**Recommendation**: Option B (direct button) for fastest UX. Add toast: "Terminal released. Session closed."

### 6. Service Implementation: Reuse Existing Lifecycle

**Decision**: Reuse `sessionLifecycleService.startClosing()` and `sessionLifecycleService.finalizeClosing()` already being called in other services. No need to rewrite state transition logic.

**Rationale:**
- Already battle-tested for session state management
- Keeps transaction and lock handling consistent
- Minimizes new code

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| **Backward compatibility**: Existing code calling closeSession with cash parameters will break | No external callers; only internal endpoint. Controlled deprecation via route semantics. All changes within one module. |
| **Audit trail**: Losing counted cash at close time** | Supervisor will record actual cash received during finalize. Close "reason" provides optional context. Finalize event records the official reconciliation. |
| **User confusion**: Cashiers expect close to do variance checking | Training + UI clarity: modal removed, close button becomes simple "release terminal" action. Help text explains finalization happens later. |
| **Supervisor forgets to finalize**: Sessions stuck in CLOSED state | Dashboard or report showing pending CLOSED sessions (already in design scope, separate task). Email reminders (future work). |

## Migration Plan

1. **Code changes** (low risk, no data migration):
   - Modify `PosSessionCloseService`
   - Update `StorePosSessionCloseRequest`
   - Simplify `PosSessionController::closeFinalize()`
   - Update sell.blade.php to remove close modal

2. **Testing**:
   - Existing close tests may need updates (remove variance assertions)
   - Add tests for simple close behavior (transition only)
   - Run finalize tests to ensure they're unaffected

3. **Deployment**:
   - No database migrations
   - No permission changes
   - Code-only deployment; can be deployed independently

4. **Rollback**:
   - Revert code changes
   - Restore old sell.blade.php modal from git history
   - No data cleanup needed

## Open Questions

- Should close log the reason anywhere beyond metadata? (Recommended: log to Laravel log for audit trail)
- Should we send a notification to supervisor when a session is closed, as a reminder to finalize? (Deferred: future notification system)
- Should we add a timestamp to metadata tracking when close happened, separate from `closed_at`? (No: `closed_at` already exists)
