## Why

POS session management has three operational gaps: (1) terminal cash thresholds configured in policy are never displayed or enforced in the UI, (2) the "Kas" column should consistently show expected cash for operational visibility (currently switches to NULL on close), and (3) non-terminal sessions lack clarity around finalize requirement.

## What Changes

- **Terminal Threshold Display & Alert**: Display `cash_threshold` from terminal policy in sessions list. Highlight row with warning color when expected cash exceeds threshold.
- **Consistent Kas Column Display**: Ensure "Kas" column always shows `expected_cash_total` for both OPEN and CLOSED/CLOSING sessions (not NULL), providing consistent operational context.
- **Non-Terminal Session Finalize Clarity**: Confirm non-terminal sessions skip finalize and close directly. Add tooltip to disabled finalize button.

## Capabilities

### New Capabilities
- `pos-terminal-threshold-display`: Display terminal cash thresholds in session list view and visually alert when thresholds are exceeded.

### Modified Capabilities
- `pos-session-lifecycle`: Ensure "Kas" column displays expected_cash_total consistently across session states. Clarify finalize is not applicable for non-terminal sessions.

## Impact

- **Backend**: PosSessionController (index query verification for eager loading terminal.policy)
- **Frontend**: Session index view (add threshold display column + conditional row styling, verify Kas column logic)
- **Database**: No schema changes needed (cash_threshold already exists on pos_terminal_policies)
- **UX**: Sessions list displays more operational context (threshold and consistent cash values)
