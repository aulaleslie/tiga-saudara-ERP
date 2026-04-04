## Context

The codebase already completed a first POS authorization cleanup that moved several behaviors away from role-name heuristics and toward explicit permissions. That work solved the immediate mismatch between named roles and runtime guards, but it did not finish the admin model. The current state still has three structural problems:

1. Runtime authorization, menu visibility, and permission registry entries are not in full parity. Some permissions are defined but dormant, some are used at runtime but not represented cleanly in the centralized registry, and some screens are gated differently at route, controller, and UI layers.
2. The business now wants four stable operational roles, `owner`, `manager`, `cashier`, and `floor staff`, but the system still exposes POS access mostly as a flat list of raw permissions. That makes it too easy to create role combinations that are technically valid but operationally incoherent.
3. High-risk POS actions such as checkout completion, cross-session oversight, and transaction override need to remain granular, but the granularity must sit under a predictable bundle model so Super Admin can reason about assignment safely.

This change is cross-cutting because it touches centralized permission config, role-composition expectations, POS route/controller/view guards, and the spec contract for several existing POS flows.

## Goals / Non-Goals

**Goals:**
- Establish four supported POS operational role bundles: `owner`, `manager`, `cashier`, and `floor staff`.
- Preserve action-level control for risky POS operations while making the default admin model bundle-first instead of flat-permission-first.
- Make `owner` map to the existing Super Admin bypass, not to a second parallel authorization system.
- Make `manager` a fully assignable role bundle that can cover all POS operations without global bypass.
- Make `cashier` the default payment-authorized sell role.
- Make `floor staff` able to enter the POS shell, prepare a transaction, save it, and load it for handoff, but not enter payment flow.
- Preserve terminal-less sessions for handoff and intervention workflows while making terminal assignment a cashier-specific prerequisite for checkout.
- Bring POS permission registry entries into parity with runtime checks and explicitly deprecate unsupported or dormant permissions.

**Non-Goals:**
- Redesign the POS user interface or change unrelated sale/cart/payment mechanics.
- Remove Super Admin gate bypass behavior.
- Replace Spatie permissions with a new authorization library.
- Eliminate granular permissions entirely; granularity is retained, but organized under supported bundles.
- Solve all non-POS role-management UX problems in the same change.

## Decisions

### Decision 1: Adopt supported role bundles as the primary POS admin contract

**Choice:**
The system will define four supported POS role bundles:
- `owner`: Super Admin bypass
- `manager`: full POS operational authority without global bypass
- `cashier`: end-to-end sell and payment authority
- `floor staff`: shell, cart preparation, save/load handoff authority without payment authority

These bundles are the supported mental model for admins. Raw permissions remain the runtime mechanism, but the supported role names and their default bundles become explicit product behavior.

**Rationale:**
- This matches the business language the system is now expected to support.
- It gives Super Admin a predictable assignment model while still retaining granular exceptions where necessary.
- It reduces the risk of invalid combinations such as “can finalize payment but cannot enter shell” or “can approve cash variance but cannot access oversight pages”.

**Alternatives considered:**
- Keep raw permissions only and rely on admin discipline: rejected because that is the current source of drift.
- Replace the permission model with hardcoded roles: rejected because the business still needs granular exceptions and delegated assignment.

### Decision 2: Keep granular permission checks, but group them into admin-facing capability clusters

**Choice:**
POS runtime permissions remain explicit and fine-grained, but they will be organized conceptually into capability clusters:
- Core shell access
- Draft and transaction handoff
- Checkout and receipt actions
- Session operations
- Oversight and approval
- Terminal and POS administration
- Direct exception actions

The change does not require a new authorization engine. It requires a normalized registry and a documented bundle matrix.

**Rationale:**
- Code paths already rely on fine-grained permissions for cart exceptions and approval flows.
- Preserving those checks avoids breaking behavior while still making admin assignment understandable.
- This allows manager/cashier/floor staff bundles to remain opinionated without removing targeted exceptions.

**Alternatives considered:**
- Collapse multiple POS actions into a few broad permissions: rejected because that would reduce the control the business explicitly wants.
- Expose every permission equally in the role UI: rejected because it makes unsupported combinations too easy to create.

### Decision 3: Treat payment authority as the cashier boundary, with cashier terminal dependency

**Choice:**
`pos.checkout.payment` remains the authority boundary for all payment-stage and finalize behavior. `cashier` and `manager` bundles include it by default; `floor staff` does not.

Any POS shell behavior needed for handoff, including building the cart, selecting customer, assigning serials, saving drafts, and loading drafts, remains usable without checkout permission.

Checkout eligibility is further constrained by terminal context:
- `cashier` may use staged payment and finalize checkout only when the active session has a terminal assigned
- `manager` may use staged payment and finalize checkout even when the active session has no terminal assigned
- `floor staff` may open and use terminal-less sessions for handoff work but never for payment

**Rationale:**
- This is the clearest business distinction between cashier and floor staff.
- It keeps cashier payment tied to a concrete register context without forcing every session to be terminal-bound.
- It preserves manager intervention ability for exceptional checkout handling from terminal-less sessions.
- It matches existing runtime direction already established in the earlier permission-driven cleanup.
- It preserves a practical handoff workflow instead of limiting floor staff to a useless “open shell but do nothing” state.

**Alternatives considered:**
- Make floor staff save-only and forbid reload: rejected because handoff correction often requires loading an existing draft back into the shell.
- Let floor staff enter staged payment but block finalize: rejected because partial payment access creates ambiguous operational state.
- Require every checkout-authorized bundle to have a terminal-assigned session: rejected because it blocks manager intervention and conflicts with the desired terminal-less session workflow.

### Decision 4: Manager authority stays explicit, not bypass-based

**Choice:**
Manager bundle authority will be expressed through explicit permissions such as session oversight, close-any, variance approval, approval queue access, reports, reconciliation, terminal administration, and transaction override authority. Managers do not receive gate-level bypass; only Super Admin retains that.

**Rationale:**
- This preserves a meaningful distinction between owner and manager.
- It keeps the POS model auditable and predictable.
- It allows Super Admin to adjust manager breadth without creating an unbounded implicit authority role.

**Alternatives considered:**
- Give managers owner-like bypass: rejected because it weakens the meaning of Super Admin and hides too much behind Gate::before.

### Decision 5: Permission registry parity is a product requirement, not an implementation detail

**Choice:**
The centralized POS permission registry must be brought into parity with runtime behavior:
- runtime-used permissions must be registered and assignable
- registered permissions must either be enforced, grouped as derived support permissions, or explicitly deprecated
- dormant or misleading permissions must not remain silently active in the role editor

This includes formalizing important runtime permissions such as transaction override authority and resolving drift around receipt reprint, approval queue visibility, monitor access, and obsolete terminal-requirement semantics.

**Rationale:**
- Bundle-first administration only works if the registry is trustworthy.
- Permission drift is currently one of the reasons the POS model feels messy.
- This makes tests, docs, and operations align to the same contract.

**Alternatives considered:**
- Leave dormant permissions in config indefinitely for backward compatibility: rejected because it preserves ambiguity and assignment mistakes.
- Fix runtime checks only and ignore registry parity: rejected because the role editor would remain misleading.

## Risks / Trade-offs

- [Existing live roles may not map cleanly to one of the four supported bundles] -> Mitigation: provide a migration matrix that classifies each current POS role into owner, manager, cashier, floor staff, or custom-exception role before enforcement changes ship.
- [Deprecating dormant permissions could surprise admins who already assigned them] -> Mitigation: mark them deprecated first, document replacements, and avoid destructive removal until role migration is complete.
- [Manager bundle could become too broad and effectively mimic owner] -> Mitigation: keep owner-only behavior limited to Super Admin bypass and explicitly review which override permissions belong to manager.
- [Grouping permissions for admin clarity may obscure that some actions are still individually controllable] -> Mitigation: document bundle defaults and identify exception permissions that remain separately assignable.

## Migration Plan

1. Define the supported POS role bundles and target permission matrix in specs.
2. Audit current POS permissions and classify each as active, missing-but-required, grouped exception, or deprecated.
3. Align centralized permission config and role-management surfaces with the audited set.
4. Update runtime authorization and UI/menu visibility where they still diverge from the intended bundle matrix.
5. Backfill or remap live POS roles to the supported bundles, with custom exceptions called out explicitly.
6. Update regression coverage for shell access, save/load handoff, checkout authority, session closure, and manager oversight paths.

**Rollback:**
- Revert the role-bundle enforcement and parity changes while leaving any additive permission registrations in place.
- Keep deprecated permissions available but non-primary if rollback is needed to restore old assignments quickly.

## Open Questions

- Should `cashier` include receipt reprint by default, or should receipt reprint remain a manager-managed exception?
- Should manager bundle include direct price override, or should that remain separately assignable even for managers?
- How much grouping or guidance should the role-management UI expose for deprecated POS permissions during the transition window?
