## Context

POS supervised cart actions are permission-gated, but quantity-reduction UI branch selection currently depends on a frontend capability key that is not guaranteed in the backend payload. `PosRolePolicyService::capabilityFlags()` currently publishes `direct_permissions.qty_reduce`, while `sell.blade.php` reads `roleCapabilities.can_reduce_quantity` and treats missing values as privileged (`true`).

This mismatch causes non-privileged users to enter privileged render paths, hiding pending approval controls (`Periksa Persetujuan`) even when `line.pending_approvals` contains `QTY_REDUCE` requests.

## Goals / Non-Goals

**Goals:**
- Publish a canonical `can_reduce_quantity` capability in backend role capabilities.
- Make frontend capability resolution fail-safe to restrictive behavior when capability payloads are partial or evolving.
- Preserve existing approval flow behavior and endpoints while restoring pending/approved control rendering for non-privileged users.
- Add regression coverage for capability contract integrity.

**Non-Goals:**
- Redesign approval request lifecycle, queue behavior, or token semantics.
- Introduce new approval action types or alter backend approval API contracts.
- Change cart table layout, button labels, or unrelated POS permissions.

## Decisions

1. Canonical capability flag at backend boundary
- Decision: Add top-level `can_reduce_quantity` to `PosRolePolicyService::capabilityFlags()` and derive it from the same permission source as `direct_permissions.qty_reduce`.
- Rationale: The frontend already uses this key; publishing it explicitly restores a stable contract.
- Alternatives considered:
  - Frontend-only fix using `direct_permissions.qty_reduce`: resolves current page but leaves payload contract inconsistent for other consumers.
  - Rename/remove `direct_permissions`: larger compatibility risk and unnecessary for this fix.

2. Frontend fallback and restrictive default
- Decision: Resolve reduce capability in order: `can_reduce_quantity` (if boolean) -> `direct_permissions.qty_reduce` -> `false`.
- Rationale: Prevents fail-open behavior when payload shape drifts; missing data should not grant privileged controls.
- Alternatives considered:
  - Trust only `can_reduce_quantity`: still fragile during partial rollouts or stale payloads.
  - Keep current `!== false` logic: continues fail-open regression.

3. Contract regression coverage
- Decision: Add tests that assert capability payload consistency (`can_reduce_quantity` exists and matches `direct_permissions.qty_reduce`) and that non-privileged paths remain approval-driven.
- Rationale: This defect originated from backend/frontend contract drift that existing approval tests did not validate.
- Alternatives considered:
  - Rely on manual QA only: insufficient to prevent recurrence.

## Risks / Trade-offs

- [Duplicate capability fields can diverge] -> Mitigation: derive both from identical permission check and assert equality in tests.
- [Restrictive fallback may temporarily require extra approvals for malformed payloads] -> Mitigation: acceptable fail-safe behavior; surfaces payload issues without granting excess privilege.
- [Partial deploys can still produce inconsistent behavior] -> Mitigation: deploy backend and frontend together in one release; verify with smoke checks immediately after deploy.

## Migration Plan

1. Ship backend capability contract and frontend fallback in the same release.
2. Run targeted POS feature tests covering permission enforcement and supervised qty reduction.
3. Smoke test with a non-privileged cashier account: request qty reduction, confirm `Periksa Persetujuan` renders during `PENDING` and survives refresh.
4. Rollback strategy: revert change set; no schema/data migration rollback required.

## Open Questions

- Should `can_reduce_quantity` be documented as the preferred public key for all POS frontend consumers, with `direct_permissions.qty_reduce` treated as legacy compatibility?
