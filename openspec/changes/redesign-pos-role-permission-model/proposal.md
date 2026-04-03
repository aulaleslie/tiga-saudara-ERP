## Why

The POS permission surface has grown into a mix of runtime checks, menu gates, approval rules, and registry entries that no longer form a coherent admin model. Super Admin can still bypass all guards, but non-owner POS roles are becoming hard to compose safely because some permissions are dead, some are under-enforced, and some important behaviors are not represented cleanly as role bundles.

This needs to change now because the business wants four stable operational roles, `owner`, `manager`, `cashier`, and `floor staff`, while still keeping action-level access granular. Without a redesign, role assignment will keep drifting into confusing combinations that expose the wrong screens or block critical handoff and checkout flows.

## What Changes

- Define an official POS role bundle model for `owner`, `manager`, `cashier`, and `floor staff`, with each bundle expressed through explicit permissions rather than role-name inference or ad hoc admin convention.
- Introduce a POS access matrix that maps screens and actions to the intended default role bundles, especially separating shell access, draft handoff, checkout payment, session oversight, and terminal/reporting access.
- Align the centralized POS permission registry with runtime behavior so every runtime-checked permission is registered, every registered permission is either enforced or explicitly deprecated, and risky actions remain individually controllable.
- Clarify that `owner` maps to Super Admin bypass, `manager` is a fully assignable role managed by Super Admin, `cashier` can complete payment only when operating from a terminal-assigned session, and `floor staff` can prepare, save, and load POS transactions but cannot enter payment flow.
- Clarify that POS sessions may still be opened without a terminal, but checkout eligibility depends on both bundle and terminal context: manager may check out without a terminal-assigned session, cashier may not, and floor staff remains handoff-only.
- **BREAKING** Reassign live POS roles to the new role bundles and retire or hide deprecated POS permissions that no longer map to supported behavior.

## Capabilities

### New Capabilities
- `pos-role-bundles`: define the supported POS operational roles, their default permission bundles, and the required screen/action access matrix.
- `pos-permission-governance`: define how POS permissions are surfaced, validated, deprecated, and kept in parity with runtime authorization.

### Modified Capabilities
- `pos-sell-save-new`: clarify that both cashier and floor staff bundles can save drafts from the POS shell, while payment access remains separate.
- `pos-transaction-handoff-visibility`: clarify which roles can view, load, continue, cancel, or override ownership of saved POS transactions.
- `pos-multi-stage-payment-flow`: constrain staged payment flow to bundles that include checkout authority, with cashier requiring an active terminal assignment while manager does not.
- `pos-checkout-finalize-integration`: constrain checkout completion to bundles that include checkout authority, with cashier requiring an active terminal assignment while manager does not.
- `pos-session-close`: clarify the expected separation between cashier own-session closure, manager administrative session closure, and owner bypass.

## Impact

- Affected code: `app/Config/Permissions.php`, permission seed/sync flows, POS route middleware, request authorization, POS role capability helpers, session/transaction policy services, and sell/session/transaction Blade views.
- Affected admin workflows: role creation/editing, POS permission assignment, and any role-management UI that currently exposes raw POS permissions without grouping or deprecation guidance.
- Affected tests/specs: POS role matrix tests, checkout authorization tests, transaction handoff tests, session close tests, and navigation/menu visibility coverage.
- Affected operations: existing live POS roles will need a migration review so their assigned permissions land on one of the supported bundles before the new model is enforced.
