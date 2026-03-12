## Context

Split checkout currently resolves customer identity using source-setting ownership checks in two places: split-group pre-resolution and inline posting lookup. Those checks reject valid customers when `customers.setting_id` differs from the split group owner, even though broader POS flow treats customers as globally resolvable by `customers.id`.

The fix must align split checkout with global-customer behavior while preserving existing split posting invariants: posting ownership by `source_setting_id`, per-source numbering behavior, totals reconciliation, and idempotent finalize semantics.

## Goals / Non-Goals

**Goals:**
- Make split checkout customer resolution global by `customers.id` for selected and walk-in fallback paths.
- Ensure unresolved failures occur only when no valid customer record can be resolved by ID.
- Preserve split posting ownership and numbering behavior by `source_setting_id`.
- Protect behavior with regression tests covering mixed-owner success and truly unresolved failure paths.

**Non-Goals:**
- Redesign customer ownership model or add customer synchronization.
- Change checkout UI or customer selection payload shape.
- Change split grouping, tax resolution, totals reconciliation, or idempotency algorithm.

## Decisions

### 1) Remove `setting_id` filters from split-group customer resolution
- Decision: In `PosCheckoutGroupCustomerResolverService`, resolve selected customer by `customers.id` only; fallback walk-in lookup by configured `pos_walk_in_customer_id` by ID only.
- Rationale: This matches existing POS customer request validation, search, and non-split checkout resolution semantics.
- Alternative considered: Keep source-setting ownership checks and duplicate customer records across settings.
  Rejected because it preserves the regression and forces unnecessary data coupling.

### 2) Remove posting-setting ownership filter from inline posting customer validation
- Decision: In `InlinePosCheckoutPostingAdapter`, validate customer by ID existence only before sale creation.
- Rationale: Prevents downstream rejection after pre-resolution succeeds and keeps one consistent customer rule across the split pipeline.
- Alternative considered: Keep stricter adapter-level check as a guardrail.
  Rejected because it conflicts with intended domain rule and causes inconsistent behavior between stages.

### 3) Keep ownership attribution and numbering strictly source-scoped
- Decision: Do not change logic assigning `sales.setting_id`, transaction ownership, or source-based numbering prefixes.
- Rationale: Customer identity scope and posting ownership scope are separate concerns; only customer lookup policy changes.
- Alternative considered: Tie ownership scope to customer ownership.
  Rejected because it would alter accounting/reporting semantics beyond the defect scope.

### 4) Encode regression expectations explicitly in split posting tests
- Decision: Update failure-path assertions that depended on source-setting customer membership; add explicit unresolved tests for missing IDs and invalid walk-in fallback.
- Rationale: Prevents silent reintroduction of `setting_id` customer gating.
- Alternative considered: Minimal test edits only where currently failing.
  Rejected because it does not adequately protect the intended global-customer contract.

## Risks / Trade-offs

- [Cross-business customer visibility assumptions in downstream reports] -> Mitigation: Keep ownership attribution unchanged and verify report joins rely on sale ownership, not `customers.setting_id`.
- [Invalid/stale `pos_walk_in_customer_id` configuration still causes unresolved failures] -> Mitigation: Preserve actionable unresolved error payload and include explicit coverage for invalid fallback IDs.
- [Overlapping in-flight OpenSpec changes may touch same split posting files/specs] -> Mitigation: Keep this change narrowly scoped to customer resolution policy and rebase/merge carefully during implementation.

## Migration Plan

1. Ship code changes behind existing split checkout path (no feature flag required).
2. Run targeted POS split posting and idempotency tests.
3. Validate staging scenarios for mixed-owner selected customer, mixed-owner walk-in fallback, and truly unresolved customer.
4. Roll back by reverting this change set if unexpected side effects appear.

## Open Questions

- Should a follow-up capability explicitly codify global customer policy across all POS flows (not only split posting) for broader consistency checks?
