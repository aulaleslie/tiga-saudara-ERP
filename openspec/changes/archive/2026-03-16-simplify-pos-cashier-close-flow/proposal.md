## Why

The POS session close flow currently mixes terminal release with cash reconciliation, blocking cashiers from releasing terminals while variance approval is pending. This contradicts the two-stage settlement design that separates terminal closure (immediate) from cash finalization (delayed). Simplifying the close action to pure terminal release unblocks the intended workflow and eliminates confusing error messages about variance approval during close.

## What Changes

- **Remove variance calculation from close**: Eliminate all cash counting, variance calculation, and supervisor approval logic from the cashier close endpoint
- **Remove supervisor involvement from close**: No longer require or accept supervisor credentials (`supervisor_identifier`, `supervisor_pin`) during close
- **Simplify close action to terminal release**: Transition session status from OPEN/CLOSING to CLOSED with minimal data (just optional reason for audit)
- **Remove close modal from sell page**: Eliminate the cash counting form entirely; close becomes a simple "release terminal" action
- **Move reconciliation to finalize**: All cash counting, variance approval, and settlement now happens exclusively during the finalize stage (already implemented via `PosSessionFinalizeService`)

## Capabilities

### New Capabilities

- `pos-simple-terminal-release`: Cashier can release terminal immediately without cash counting or supervisor involvement. Session transitions OPEN/CLOSING → CLOSED instantly, freeing the terminal for reuse.

### Modified Capabilities

- `pos-session-close`: Changed from two-part (counting + variance approval) to pure terminal release (no counting, no approval, immediate transition to CLOSED)

## Impact

- **Service Changes**: `PosSessionCloseService.closeSession()` signature simplified; removes `counted_cash_total`, `counted_denominations`, `supervisor_identifier`, `supervisor_pin` parameters
- **Request Validation**: `StorePosSessionCloseRequest` now only accepts `reason` (optional string for audit)
- **Controller**: `PosSessionController::closeFinalize()` no longer handles variance approval blocking; simpler error handling
- **Frontend**: sell.blade.php close modal removed; replaced with simple confirm action (or automatic close on button click)
- **Behavior Change**: Cashiers see immediate success on close; no approval barriers at this stage
- **Database**: No schema changes needed
- **Permissions**: No new permissions needed; existing `pos.sessions.close` permission unchanged
