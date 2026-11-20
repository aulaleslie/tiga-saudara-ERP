# Proposal

## Why

Cash Pickup and other POS screens expose expected drawer cash without a dedicated visibility permission, including through browser payloads and variance. Cash pickup also rejects amounts above the expected balance, preventing staff from recording actual pickups when the ledger differs from physical cash.

## What Changes

- Register `pos.sessions.viewExpectedCash` as **Lihat Ekspektasi Kas** in the unified permission manager and expose it as an explicit POS exception.
- **BREAKING**: Hide expected cash, variance, and revealing reconciliation data throughout POS for users lacking this permission, including session owners and supervisors. Preserve Admin permission synchronization and Super Admin bypass; do not automatically grant it to ordinary roles or bundles.
- Keep permitted counting, closing, finalization, and pickup actions usable without revealing the balance; existing action authorization remains independent.
- Remove browser, controller, and safe-drop service caps based on expected cash. Positive approved pickups may create or deepen negative balances without extra approval.
- Use focused permission, response, pickup, ledger, and finalization verification rather than a mandatory full test-suite run.

## Capabilities

### New Capabilities

- `pos-expected-cash-visibility`: Explicit visibility permission, unified assignment, and protection of direct and derived balance information across POS.

### Modified Capabilities

- `pos-cash-pickup-live-expected`: Permission-aware live balance display without client-side balance caps.
- `pos-session-lifecycle`: Permission-aware Kas column across every lifecycle state.
- `pos-supervisor-cash-finalization`: Conditional reconciliation and variance disclosure while retaining server-side approval rules.
- `pos-session-cash-reconciliation`: Permission-aware reconciliation presentation and signed ledger balances without pickup limits.

## Impact

Targets `app/Config/Permissions.php`, `Modules/User/Helpers/PermissionHelper.php` consumers and permission sync, `Modules/Pos/Support/PosPermissionMatrix.php`, POS session/pickup controllers, safe-drop and summary services, sell/nav/modal views and scripts, session lists/details/finalization, and other POS report/reconciliation responses exposing these values. No new dependency or historical cash-event rewrite is intended. Existing specs that require unconditional cash display or an available-pickup limit are deliberately narrowed by the delta specs.
