## Context

The current POS flow mixes two incompatible authorization models:

1. Explicit permissions such as `pos.access`, `pos.sell`, `pos.transactions.save`, and `pos.overrides.price`
2. Role-name inference in `PosRolePolicyService::detectRole()` and downstream checks that treat names containing `floor`, `cashier`, `kasir`, or `manager` as authoritative behavior selectors

That mixture causes three concrete problems in the live system:

- Terminal selection is currently inferred from `pos.sell`, so a role cannot both enter the POS shell and remain exempt from terminal/opening-float requirements.
- Checkout authority is only partially permission-driven. `canCheckout()` references `pos.checkout.payment`, but the permission is not exposed in the POS permission registry and the final decision still falls back to role-name detection.
- Some privileged cart actions, especially price override, still suppress direct authorization based on inferred role names even when explicit permissions are granted.

The result is that `Pembantu Kasir` cannot be modeled as intended through permissions alone. The desired bundle is: enter POS shell, save draft, no payment finalization, and no mandatory terminal/opening cash. That bundle is valid operationally, but the current authorization model cannot express it without either changing the role name or overloading unrelated permissions.

## Goals / Non-Goals

**Goals:**
- Make POS runtime behavior derive from explicit permissions, not from role names.
- Separate POS shell/cart access from checkout-payment authority.
- Separate session-open terminal requirement from POS shell access.
- Preserve existing draft handoff flow while allowing non-checkout users to use `Simpan dan Buka Baru`.
- Make all permissions used in POS runtime code available in the permission registry so roles can be composed intentionally.
- Update specs and tests to describe permission combinations instead of named roles.

**Non-Goals:**
- Redesign the POS UI layout or change core cart/payment workflows unrelated to authorization.
- Remove the existing `pos.sell` permission key from the system.
- Introduce a brand-new role system; roles remain permission bundles managed by the business.
- Change transaction persistence, session anti-clash rules, or approval-token mechanics beyond the authorization boundary.

## Decisions

### Decision 1: Use an explicit three-way capability split for POS shell, checkout, and terminal requirement

**Choice:**
- Keep `pos.sell` as the permission that grants access to the POS shell/cart routes.
- Use `pos.checkout.payment` as the permission that grants access to payment-stage endpoints and checkout finalization.
- Add `pos.sessions.require-terminal` as the permission that makes terminal selection and opening float mandatory during session opening.

**Rationale:**
- This is the smallest change that can represent the desired helper role behavior.
- It avoids reworking the whole route tree around a new shell permission key.
- It makes the intended role bundles easy to reason about:
  - `pos.sell` + `pos.transactions.save` + no `pos.checkout.payment` + no `pos.sessions.require-terminal` = helper handoff operator
  - `pos.sell` + `pos.transactions.save` + `pos.checkout.payment` + `pos.sessions.require-terminal` = cashier

**Alternatives considered:**
- Introduce a brand-new shell permission such as `pos.shell.access`: rejected for now because it would create broad route churn without solving more than the existing `pos.sell` key can solve.
- Keep using `pos.sell` as both shell and checkout authority: rejected because that is the current coupling causing the helper-role problem.

### Decision 2: Permission helpers remain centralized, but role-name detection stops being authoritative

**Choice:**
`PosRolePolicyService` will be refactored into a permission-driven capability helper. Its public outputs such as `requiresTerminalSelection()` and `canCheckout()` remain useful to controllers/views, but they will derive only from permissions. Any retained `detectRole()` output becomes descriptive/logging-only and is not allowed to make authorization decisions.

**Rationale:**
- Centralized capability helpers keep the controller and Blade changes small.
- This preserves the existing `roleCapabilities` integration pattern in the POS sell/session views while removing the unsafe dependency on role naming.
- It provides a single place to encode permission combinations for UI flags.

**Alternatives considered:**
- Remove the service entirely and inline `user->can()` checks everywhere: rejected because the POS shell already depends on shared capability flags and would become inconsistent quickly.
- Keep `detectRole()` as an authorization fallback: rejected because it recreates the ambiguity the change is meant to remove.

### Decision 3: Payment flow must be gated consistently across route, controller, and UI layers

**Choice:**
All payment-entry and payment-completion paths will require `pos.checkout.payment`. That includes the payment method search used by the checkout modal, staged payment submission, payment-chain recovery/reset, and checkout finalization. The sell UI will expose payment actions only when the user has checkout permission.

**Rationale:**
- Current code blocks finalization but leaves earlier staged-payment endpoints reachable from the same `pos.sell` route group.
- The desired helper behavior is “cannot proceed with payment,” which must cover the full payment path, not just the last POST.
- Route-level gating reduces accidental exposure; controller checks and UI flags provide defense in depth and clearer user feedback.

**Alternatives considered:**
- Only guard `checkoutFinalize()`: rejected because a non-authorized user could still enter partial payment flow and create confusing session state.
- Only hide the button in the UI: rejected because the backend must remain authoritative.

### Decision 4: Direct privileged cart actions are controlled by direct permissions only

**Choice:**
For price override and other privileged cart actions, direct execution is allowed when the user has the relevant explicit permission. If the permission is absent, approval flow applies. Role-name-based “manager only” suppression is removed.

**Rationale:**
- This matches the stated requirement that permissions, not role names, are authoritative.
- It keeps the approval system generic: direct permission grants bypass; lack of permission routes through approval.
- It allows businesses to define unusual but valid bundles without code changes.

**Alternatives considered:**
- Keep a hardcoded “manager-only” exception for `pos.overrides.price`: rejected because it means explicit permission assignment is still not authoritative.
- Replace all direct permissions with approval-only governance: rejected because the existing permission system already models direct authority effectively.

### Decision 5: Permission registry must be brought into parity with runtime checks

**Choice:**
Add all runtime-used POS permissions to `app/Config/Permissions.php`, especially `pos.checkout.payment`, `pos.sessions.require-terminal`, and any cart-action permissions already used by runtime authorization but not exposed in the registry.

**Rationale:**
- A permission-driven model fails if admins cannot actually assign the permissions the code checks.
- This change is not complete if the code uses permissions that the role editor cannot surface.

**Alternatives considered:**
- Leave latent permissions as hidden implementation details: rejected because it recreates undocumented capability drift between runtime and role management.

## Risks / Trade-offs

- [Existing POS roles may lose or gain behavior when new permissions are introduced] -> Mitigation: define an explicit migration matrix for current cashier/helper/manager roles and add regression tests for each bundle.
- [Reinterpreting `pos.sell` as shell access may surprise admins who currently read it as “can complete sales”] -> Mitigation: update permission labels/help text or supporting documentation to distinguish shell access from checkout authorization.
- [Payment route segmentation may block existing users immediately after deployment if roles are not backfilled] -> Mitigation: seed/register the new permission first, then update live roles before deploying the new route/controller guards.
- [Retaining `detectRole()` for non-authoritative purposes could invite future misuse] -> Mitigation: document in code/design that role-name detection is informational only and must not gate behavior.

## Migration Plan

1. Register new/runtime-missing POS permissions in the permission config and seed them into the database.
2. Refactor capability helpers and runtime guards so terminal requirement, payment authority, and direct-action authorization are permission-driven.
3. Update POS views and route middleware composition to match the new capability split.
4. Backfill live roles:
   - Cashier/payment-completion roles receive `pos.checkout.payment`.
   - Roles that must select a terminal receive `pos.sessions.require-terminal`.
   - Helper/handoff roles that should use the POS shell receive `pos.sell` and `pos.transactions.save`, but do not receive `pos.checkout.payment` or `pos.sessions.require-terminal`.
   - Grant `pos.sessions.view` only to roles that should access the sessions list.
5. Run targeted POS regression coverage for session opening, sell-shell access, staged payment, finalize, save-and-new, and price override.

**Rollback:**
- Revert route/controller/service changes to the previous authorization model.
- Leave newly seeded permissions in place but unused; this is low-risk compared with destructive permission removal.
- Restore prior role assignments only if required to recover access quickly.

## Open Questions

- Should `payment-methods/search` be fully gated by `pos.checkout.payment`, or is it sufficient to gate only stage/finalize endpoints and hide the UI entry points?
- Should the UI label for `pos.sell` be renamed in the permission registry to make its shell-access meaning clearer?
- Should `detectRole()` be retained for analytics/logging, or removed entirely to prevent future authorization drift?
