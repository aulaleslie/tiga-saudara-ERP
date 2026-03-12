## Context

POS checkout finalize currently posts a single sale/payment/dispatch set from one checkout payload. The Phase 3 requirement is to keep the cashier interaction unchanged while generating accounting and inventory documents per source and tax context. The implementation must remain backward compatible for existing finalize clients and must preserve idempotent replay behavior already enforced in checkout.

Primary constraints:
- Split key is fixed: `source_setting_id + source_location_id + tax_bucket`.
- Serial lines derive source from serial ownership/location; non-serial lines derive source from stock allocation.
- Tax fallback is fixed: default tax first, then latest active tax.
- Existing fields `sale_id`, `sale_payment_id`, and `dispatch_ids` must continue to exist in finalize response and checkout storage for legacy consumers.

Stakeholders:
- Cashier users (no flow change)
- Finance/reconciliation users (must trace each checkout to multiple sale documents)
- POS engineering (must avoid regressions in finalize, serial, and idempotency behavior)

## Goals / Non-Goals

**Goals:**
- Add deterministic split planning before posting, grouped by source+location+tax bucket.
- Post one sale + payment allocation + dispatch set per group within one finalize transaction boundary.
- Persist checkout-to-group mapping for reconciliation and replay stability.
- Extend finalize response with grouped arrays while preserving legacy compatibility fields.
- Keep feature-flagged rollout so single-posting path can be restored quickly.

**Non-Goals:**
- No checkout UI redesign or multi-tender input changes.
- No change to cashier endpoint path or request payload contract.
- No redesign of stock allocation domain outside what split planning reads.
- No changes to Phase 4 serial modal UX in this change.

## Decisions

### 1) Introduce a dedicated split posting adapter behind feature flag
Decision:
- Add `SplitPosCheckoutPostingAdapter` and bind it conditionally in `PosServiceProvider` via `pos.checkout.split_posting.enabled`.

Rationale:
- Isolates Phase 3 complexity from existing inline adapter and allows rollback by flag toggle.

Alternative considered:
- Modify `InlinePosCheckoutPostingAdapter` in place.
- Rejected because rollback and regression containment are weaker.

### 2) Plan split groups before any sale posting side effects
Decision:
- Add `PosCheckoutSplitPlannerService` that receives resolved checkout lines/allocations and returns deterministic groups ordered by `split_key`.

Rationale:
- Ensures deterministic replay and supports pre-post validation of totals.

Alternative considered:
- Split on-the-fly while posting each line.
- Rejected because deterministic replay and error handling are harder to guarantee.

### 3) Allocate payment proportionally with deterministic rounding
Decision:
- Add `PosCheckoutPaymentSplitService` to allocate `paid_total` across split groups using largest-remainder over minor units with stable tie-break by `split_key`.

Rationale:
- Preserves exact total reconciliation and replay consistency.

Alternative considered:
- Floating-point proportional split without controlled rounding strategy.
- Rejected because it risks cent-level drift across replay or database order changes.

### 4) Persist split map in dedicated table and expose compatibility fields
Decision:
- Add `pos_checkout_sales` mapping table and store one row per split group with posted IDs and totals.
- Preserve `pos_checkouts.sale_id` and `pos_checkouts.sale_payment_id` as first-group compatibility pointers.
- Extend finalize response with `split_groups`, `sales`, and `sale_payments` arrays while preserving existing fields.

Rationale:
- Mapping table supports reconciliation, operational debugging, and deterministic replay.
- First-group compatibility avoids breaking existing consumers.

Alternative considered:
- Store only JSON summary in `pos_checkouts`.
- Rejected because relational lookups and integrity checks become weaker.

### 5) Keep tax fallback in planner stage
Decision:
- Compute effective tax bucket in planner using policy: default tax else latest active tax.

Rationale:
- Grouping must include tax bucket before posting; applying fallback later can produce mismatched grouping.

Alternative considered:
- Resolve tax only during sale creation.
- Rejected because split key would be unstable.

## Risks / Trade-offs

- [Split totals mismatch due to rounding drift] -> Use minor-unit integer math and final reconciliation assert before commit.
- [Idempotency replay returns different group order] -> Sort groups by deterministic `split_key` and persist split map on first success.
- [Backward compatibility regression in consumers expecting single sale] -> Keep legacy top-level fields sourced from first split group and add tests for legacy payload shape.
- [Operational complexity in reconciliation] -> Add explicit `pos_checkout_sales` rows and lightweight reconciliation query/report in Phase 5.
- [Feature rollout risk] -> Guard with `pos.checkout.split_posting.enabled`; fallback to inline adapter if disabled.

## Migration Plan

1. Add migration for `pos_checkout_sales` with unique `(pos_checkout_id, split_key)` and foreign keys/indexes.
2. Add nullable split summary metadata field (or metadata JSON key) in `pos_checkouts`.
3. Deploy code with split feature flag OFF.
4. Run schema migration and smoke test existing finalize flow (still inline adapter).
5. Enable feature flag for pilot setting only.
6. Validate reconciliation checks: group total sum equals checkout totals; no duplicate mappings on replay.
7. Rollout gradually to additional settings.

Rollback:
- Disable `pos.checkout.split_posting.enabled` to return to inline adapter path.
- Keep additive schema in place; no destructive rollback required.

## Open Questions

- Confirm canonical “latest active tax” query rule in this codebase (`status` vs `created_at` proxy) to avoid policy ambiguity.
- Confirm whether receipt printing should continue using first group only in Phase 3, or expose explicit group selector in Phase 5.
