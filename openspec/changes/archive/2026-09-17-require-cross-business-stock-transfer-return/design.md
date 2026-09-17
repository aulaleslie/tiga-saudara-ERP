## Context

Workflow version 2 records a route-policy snapshot when a pending transfer is approved. The resolver currently makes `mandatory_return` false for a cross-business non-PKP to non-PKP route. Forward receipt approval reads the immutable snapshot to create full-product obligations and select `AWAITING_RETURN` or `COMPLETED`; return preparation and approval also require that snapshot flag.

The agreed rule is based solely on business identity: locations under the same `setting_id` need no return, while different `setting_id` values require return. PKP status still controls destination inventory and serial tax classification. The proposal and delta specs define the behavior.

## Goals / Non-Goals

**Goals:**

- Snapshot `mandatory_return = true` for every newly approved cross-business route, including non-PKP to non-PKP.
- Use existing full-product obligation, return dispatch, and return receipt flows to complete those transfers.
- Keep prior approved snapshots and their resulting lifecycle intact.
- Verify the changed route and unchanged same-business behavior with focused tests.

**Non-Goals:**

- Change tax classification, tax resolution, stock condition handling, or return quantity rules.
- Backfill or reinterpret approved transfers, including those received after deployment.
- Redesign return documents, add schema columns, or change workflow version 1 behavior.

## Decisions

### Resolve return obligation from business identity at approval

Update `TransferRoutePolicyResolver::resolveClassification()` so the same-business branch keeps `PRESERVE` and `mandatory_return = false`; both cross-business branches keep destination classification based on destination PKP but set `mandatory_return = true`. `TransferLifecycleService::createRoutePolicySnapshot()` remains the authority for locked locations and settings, then persists the decision at approval. This keeps the rule in one resolver rather than adding receipt-time or UI-specific checks.

Alternative considered: infer the return requirement again at forward receipt from current settings. That would change an already approved transfer if a location or PKP setting changed later and would conflict with immutable route policy.

### Reuse the full-product return lifecycle

For a newly mandatory non-PKP to non-PKP route, forward receipt approval uses its existing `mandatory_return` branch to create obligations for the exact received product and condition quantities and projects `AWAITING_RETURN`. Existing return dispatch and receipt steps fulfill those obligations before completion. Keep destination classification `NON_TAX` and existing return-receipt origin classification.

Alternative considered: add a separate non-PKP return path. It would duplicate reservation, inventory, serial, and audit logic already driven by the snapshot.

### Preserve approved policies

Apply the new resolver only when a workflow version 2 transfer is approved after deployment. Draft and pending transfers take the new policy on later approval. Previously approved transfers retain their persisted `mandatory_return` value; no data migration or policy rewrite is needed.

Alternative considered: backfill snapshots for approved non-PKP to non-PKP transfers. That could create obligations after stock already moved and would change historical commitments.

## Risks / Trade-offs

- [The return path may have been exercised mostly with PKP-involved routes] → Run a focused non-PKP to non-PKP flow through approval, exact forward receipt, return dispatch, and return receipt, checking full quantities and final status.
- [A view or test may assume non-PKP to non-PKP completes at forward receipt] → Update the relevant expectation and verify the route-policy display reflects the persisted snapshot.
- [Existing approved transfers may be mistakenly treated under the new rule] → Cover an old `mandatory_return = false` snapshot in focused regression verification.

## Migration Plan

Deploy the resolver and test/spec updates together. No schema or data migration is required. On rollback, restore the prior resolver for future approvals; route policies already approved under either rule remain immutable and continue through the lifecycle indicated by their snapshot.

## Open Questions

None.
