## Why

The POS session open flow currently blocks opening a session if the opening float (Saldo Awal) does not exceed the terminal's `cash_threshold` policy setting. This incorrectly gates session creation on a monitoring/informational threshold meant for visibility into cash holdings, not for validating minimum opening balances. The cash_threshold should inform stakeholders about sessions exceeding policy limits (via the monitoring dashboard), not prevent session creation.

## What Changes

- **Remove threshold validation from session open**: `PosSessionLifecycleService.openSession()` no longer validates opening float against `terminal.policy.cash_threshold`
- **Keep threshold in policy for monitoring**: The policy field is preserved and continues to be used by summary and monitoring services for threshold-based calculations
- **Simplify opening float requirement**: Opening float now requires only `> 0` (enforced at request validation layer)

## Capabilities

### New Capabilities

None. No new capabilities being introduced.

### Modified Capabilities

- `pos-session-lifecycle`: Opening float validation is simplified—no longer requires exceeding terminal cash_threshold. Sessions can open with any positive float; threshold visibility remains available in monitoring services.

## Impact

- **Service Changes**: `PosSessionLifecycleService.openSession()` removes validation check at lines 114-120; policy object is still loaded but not validated
- **Request Validation**: `StorePosSessionOpenRequest` remains unchanged (already validates `opening_float_total: required, numeric, gt:0`)
- **Downstream Usage**: `PosSessionSummaryService`, `PosSessionMonitorService`, and `PosSafeDropService` continue using `cash_threshold` for threshold-aware calculations—no changes needed
- **User Experience**: Cashiers can now open sessions with opening floats that would previously be rejected, unblocking workflows
