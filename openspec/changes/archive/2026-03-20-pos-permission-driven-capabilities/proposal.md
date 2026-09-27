## Why

The POS authorization model currently mixes explicit permissions with role-name heuristics and overloaded permission meanings. That makes newly created roles like `Pembantu Kasir` impossible to model cleanly: the role's assigned permissions express the intended behavior, but runtime guards still infer terminal requirement, checkout authority, and direct-action privileges from role names or from `pos.sell`.

This needs to change now because the live system already exposes the mismatch. A helper role can open a session without terminal and opening cash, but cannot enter the POS shell and use draft handoff as intended, while several POS permissions referenced by code are not fully represented in the permission registry that admins use to compose roles.

## What Changes

- Replace POS authorization decisions that currently depend on role-name detection with explicit permission checks.
- Introduce an explicit permission for terminal-required session opening so terminal selection is not inferred from `pos.sell` or from role names.
- Promote `pos.checkout.payment` to a first-class POS permission and use it to gate staged payment, payment-chain recovery/reset, and checkout finalization.
- Clarify `pos.sell` as POS shell/cart access so a role can enter `/pos/sell` without automatically gaining payment authority.
- Update session-opening UI and backend validation to key terminal/opening-float visibility and requirements from the explicit terminal-required permission.
- Update draft handoff requirements so any POS-shell user with `pos.transactions.save` can use `Simpan dan Buka Baru`, without requiring a named Floor Staff or Cashier role.
- Remove role-name-based direct-authorization exceptions from privileged cart actions, especially price override, so direct permissions are authoritative.
- Align the permission registry and role-composition surface with the POS permissions actually used by runtime code.

## Capabilities

### New Capabilities
<!-- None -->

### Modified Capabilities
- `pos-session-role-terminal-allocation`: replace role-name-based terminal selection rules with explicit terminal-required permission semantics.
- `pos-sell-save-new`: define draft handoff access in terms of POS shell and save-draft permissions rather than named roles.
- `pos-transaction-handoff-visibility`: define draft continuation and payment completion expectations using explicit permissions rather than Floor/Cashier role labels.
- `pos-supervised-cart-actions`: remove role-name-based override exceptions so direct action permissions determine whether approval is required.
- `pos-multi-stage-payment-flow`: require explicit checkout-payment permission before a user can begin or continue staged payment flow.
- `pos-checkout-finalize-integration`: require explicit checkout-payment permission before checkout finalization can complete.

## Impact

- Affected code paths: POS role-policy service, session lifecycle service, cart action authorization service, POS sell/session controllers, sell/session Blade views, route middleware composition, and request authorization.
- Affected configuration: `app/Config/Permissions.php` and any role-management surfaces that expose assignable POS permissions.
- Affected roles/data: existing POS roles will need a capability review so intended behavior is expressed by permission bundles instead of role naming.
- Affected tests/specs: POS role-matrix and session-opening tests must be rewritten around permission combinations rather than named roles.
