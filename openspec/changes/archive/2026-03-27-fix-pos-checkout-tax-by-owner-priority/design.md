## Context

POS finalize traverses multiple components to produce tax-bearing checkout results:

1. `ResolvePosStockAllocationsService` assigns source location/owner chunks.
2. `PosCheckoutSplitPlannerService` computes split groups and effective tax buckets.
3. `InlinePosCheckoutPostingAdapter` persists `sales`, `sale_details`, `dispatch_details`, and stock decrements.

The mixed-owner bug surface is caused by policy drift between these stages. Split planning uses source-owner PKP (`setting.is_pkp`) to determine effective tax, while serial posting currently re-derives policy from serial tax presence. Non-serial taxable allocation also walks configured locations directly without explicit owner-priority partitioning.

## Goals / Non-Goals

**Goals:**
- Make tax outcomes deterministic and consistent across pre-check, split planning, and persisted posting.
- Ensure source-owner PKP policy is the single authority for whether a chunk is taxed.
- Introduce explicit non-serial taxable allocation priority: non-PKP sources first, then PKP, while preserving configured location order inside each priority group.
- Preserve split posting reconciliation, ownership, and idempotency behavior.

**Non-Goals:**
- Redesigning cart pricing rules, UI tax display, or receipt layout.
- Changing payment split allocation logic.
- Introducing new database schema or historical data backfill.

## Decisions

1. Define one authoritative chunk tax policy contract and reuse it end-to-end.
Rationale: Posting must consume previously derived source policy (`source_is_pkp`, effective tax id/rate) instead of recalculating with serial-only heuristics. This removes planner/posting divergence and keeps persisted sale tax aligned with planning.
Alternative considered: keep adapter-local recomputation for serial lines. Rejected because it duplicates policy logic and caused current mismatch.

2. Apply owner-priority partitioning for non-serial taxable stock allocation.
Rationale: For taxable non-serial lines, allocation order becomes:
- Priority A: chunks from source owners with `is_pkp=false`
- Priority B: chunks from source owners with `is_pkp=true`
Within each priority, preserve the existing configured location order from `SalesLocationResolver` to avoid unstable picks.
Alternative considered: use only configured location order globally. Rejected because it cannot enforce business requirement to consume non-PKP stock first.

3. Keep split grouping and key format unchanged.
Rationale: Existing `split_key = source_setting_id:source_location_id:tax_bucket` is already consumed by reconciliation/idempotency paths and external clients. Behavior changes should not break these contracts.
Alternative considered: add PKP flag into split key. Rejected as unnecessary; PKP impact is already represented by tax bucket and source setting.

4. Expand regression coverage around mixed-owner matrices.
Rationale: The failure only appears in mixed-source combinations; isolated PKP-only or non-PKP-only tests pass. New tests must cover serial and non-serial lines in one checkout and verify persisted tax fields and dispatch tax IDs.
Alternative considered: rely on existing single-case tax tests. Rejected due inadequate matrix coverage.

## Risks / Trade-offs

- [Risk: Behavioral change in stock consumption order] -> Mitigation: constrain new priority rule to non-serial taxable lines only; keep existing order for non-tax and serial assignments.
- [Risk: Rounding drift across chunk-level tax extraction] -> Mitigation: keep minor-unit arithmetic and existing extraction helpers; add assertions for group and checkout reconciliation.
- [Risk: Hidden dependency on current serial adapter recomputation] -> Mitigation: add feature tests that assert persisted `sale_details.product_tax_amount` and `dispatch_details.tax_id` under mixed-owner serial scenarios.
- [Trade-off: More resolver logic branching] -> Mitigation: keep branching explicit and documented, with unit tests per branch.

## Migration Plan

1. Implement policy-alignment changes in resolver/planner/posting services under existing checkout flow.
2. Add/adjust unit and feature tests for mixed-owner serial + non-serial scenarios and owner-priority allocation ordering.
3. Run targeted POS checkout test suites and reconcile against existing split posting/idempotency tests.
4. Deploy with no schema migration required.

Rollback strategy:
- Revert the service-level logic changes if regression is detected.
- Since no schema/data migration is introduced, rollback is code-only.

## Open Questions

- Should owner-priority allocation for non-serial taxable lines also apply when split posting is disabled, or remain finalize-path only?
- For PKP source owners where line tax context is missing (`tax_id` absent and no source fallback), should finalize fail hard or continue as non-tax according to current fallback behavior?
