## Context

The current POS transaction flow uses a single ownership-oriented policy for both collaborative draft continuation and destructive transaction cancellation. `PosTransactionPolicyService::assertCanEdit()` currently allows load, save, and cancel when the user is the draft owner or holds `pos.transactions.edit.any`, while the transaction list/detail UI mirrors that same assumption. This clashes with the intended operating model where draft loading is a normal same-setting handoff action and cancellation is a guarded destructive action.

The codebase already has a mature approval interaction for destructive cart actions such as clear cart, line remove, quantity reduce, and price override. That flow includes action-specific direct permissions, `APPROVAL_REQUIRED` fallback, supervisor approval records, approval tokens, and a continue-or-cancel UI state. Reusing that model for transaction cancellation keeps destructive authority consistent across the POS surface.

This change is cross-cutting because it touches specs, transaction policy/service/controller code, transaction list/detail UI behavior, approval action enums and request handling, and regression coverage.

## Goals / Non-Goals

**Goals:**
- Make `pos.transactions.load` the authority for loading any mutable POS draft in the same setting.
- Remove draft ownership as the normal runtime gate for loading shared POS drafts.
- Treat POS transaction cancellation as a destructive action guarded by `pos.void` or an approval token.
- Reuse the existing clear-cart approval interaction model for transaction cancellation from both transaction list and detail views.
- Preserve the existing immutable transaction rule so completed transactions remain non-cancellable.

**Non-Goals:**
- Redesign the general POS transaction list UI beyond what is needed for the new authorization states.
- Change checkout authority or the staged payment flow.
- Introduce posted-sale reversal or broader accounting void semantics for completed transactions.
- Remove `pos.transactions.edit.any` from the permission registry entirely; this change only narrows its role in the transaction handoff/cancel flow.

## Decisions

### Decision 1: Split transaction load authority from transaction cancel authority

**Choice:**
Loading a POS draft will be authorized by same-setting scope plus `pos.transactions.load`, independent of draft ownership. Cancellation will no longer use the same policy gate and will instead require direct `pos.void` authority or an approved action token.

**Rationale:**
- Loading is collaborative and should support floor-to-cashier or cashier-to-cashier continuation without takeover friction.
- Cancellation is destructive and should not be implied by ownership or by the ability to load a draft.
- Separating these concerns makes the permission model easier to reason about and aligns runtime behavior with the desired business workflow.

**Alternatives considered:**
- Keep owner-based loading and use `pos.transactions.edit.any` for cross-user load: rejected because it blocks ordinary handoff.
- Keep owner-based cancellation while only changing load: rejected because destructive authority would still be granted implicitly to draft owners.

### Decision 2: Model transaction cancel as a first-class approval action

**Choice:**
POS transaction cancellation will become its own approval action type, with matching approval-request mapping, supervisor approval logging, token issuance, and token validation behavior.

**Rationale:**
- The existing approval infrastructure already handles direct-permission bypass and temporary delegated authority safely.
- A dedicated action type keeps approval logs and tokens specific to transaction cancellation instead of overloading unrelated cart-clear semantics.
- The UI can reuse the same pending/approved/retry interaction pattern without inventing a second destructive-action workflow.

**Alternatives considered:**
- Reuse `CART_CLEAR` approval for transaction cancel: rejected because transaction cancel targets a persisted POS transaction, not only an in-memory cart.
- Add a boolean “approved” flag directly on the transaction: rejected because it bypasses the existing token lifecycle and approval audit model.

### Decision 3: Narrow `pos.transactions.edit.any` to residual administrative behavior

**Choice:**
`pos.transactions.edit.any` will no longer be the normal path for loading another user's mutable draft. After this change, its remaining use should be limited to any truly administrative transaction behaviors still needing explicit takeover semantics, if any remain after implementation review.

**Rationale:**
- The current permission is doing two jobs: enabling ordinary collaboration and representing elevated oversight. Those jobs conflict.
- Removing it from normal load behavior prevents manager-only semantics from leaking into basic handoff.
- The permission can remain available for compatibility while its runtime use is reviewed case by case.

**Alternatives considered:**
- Remove `pos.transactions.edit.any` immediately from the model: rejected because there may be remaining administrative touchpoints or migration dependencies.

### Decision 4: Keep immutable transaction enforcement above authorization outcome

**Choice:**
Transaction status validation will remain the outer rule: `COMPLETED` transactions cannot be cancelled even when the caller has `pos.void` or a valid approval token.

**Rationale:**
- Authorization answers who may attempt the action, not whether the transaction state is still legally mutable.
- This preserves the existing invariant that completed POS transaction history is immutable.
- It avoids turning the new approval flow into a backdoor for posted-sale reversal.

**Alternatives considered:**
- Allow supervisor-approved completion reversal through the same flow: rejected because that would be a materially larger accounting and sales lifecycle change.

## Risks / Trade-offs

- [Open load authority may let more users load each other's mutable drafts than before] -> Mitigation: keep same-setting scope and explicit `pos.transactions.load` gate, and add regression coverage for missing-permission rejection.
- [Residual uses of `pos.transactions.edit.any` may become ambiguous] -> Mitigation: audit all runtime references during implementation and either keep only administrative cases or remove dead branches.
- [Adding a new approval action expands supervisor workflow surface] -> Mitigation: reuse existing approval services, UI states, and token validation patterns rather than inventing a parallel flow.
- [Transaction list/detail UI could become inconsistent if one entry point uses approval flow and the other does not] -> Mitigation: centralize cancel authorization and expose the same pending/approved/discard states in both views.

## Migration Plan

1. Update the POS transaction handoff spec and add the new cancel-authorization spec.
2. Refactor transaction policy code so load authorization and cancel authorization are handled separately.
3. Extend approval action enums, approval request mapping, and supervisor approval logging to support transaction cancellation.
4. Update transaction list/detail UI to initiate and reflect cancel approval states using the same interaction model as clear cart.
5. Replace ownership-based cancel and cross-user-load tests with the new permission and approval expectations.
6. Validate role behavior for floor staff, cashier, manager, and direct `pos.void` exception roles before rollout.

**Rollback:**
- Restore owner or `pos.transactions.edit.any` checks for load/cancel while leaving any additive approval action definitions in place.
- Hide transaction cancel approval UI if rollback is needed before the runtime behavior can be restored fully.

## Open Questions

- Should users without `pos.void` always see the `Batalkan` affordance so they can request approval, or should that affordance be limited to roles allowed to initiate approval-backed cancellation?
- After handoff is opened to all `pos.transactions.load` users, does any runtime behavior still need `pos.transactions.edit.any`, or can future cleanup retire it from the supported POS model?
