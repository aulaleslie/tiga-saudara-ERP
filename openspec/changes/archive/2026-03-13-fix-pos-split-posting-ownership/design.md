## Context

Split checkout planning already identifies each group’s `source_setting_id`, but posting still reuses terminal checkout ownership context. As a result, mixed-owner checkouts generate `sales.setting_id` and `transactions.setting_id` under the terminal setting for all groups, and sale numbering follows terminal prefixes instead of owner-specific sequences.

The finalize path must preserve current checkout ownership (`pos_checkouts.setting_id`) while posting each split group under its true source owner. This change spans split adapter orchestration, inline posting context consumption, customer resolution constraints, and regression tests.

## Goals / Non-Goals

**Goals:**
- Post each split group with owner setting = group `source_setting_id`.
- Ensure per-group sale references follow existing owner-scoped numbering behavior without introducing custom numbering logic.
- Resolve per-group customer deterministically for cross-owner posting, with actionable validation failures when unresolved.
- Preserve backward compatibility for finalize response projection fields (`sale_id`, `sale_payment_id`, `dispatch_ids`).
- Add regression tests that fail if ownership propagation or customer fallback contracts regress.

**Non-Goals:**
- Redesigning checkout UI or split payment UX.
- Introducing a new cross-setting master customer identity model.
- Changing how `pos_checkouts` ownership is stored.

## Decisions

1. Split adapter SHALL override posting owner per group before inline posting.
Rationale: `SplitPosCheckoutPostingAdapter` is the point where per-group metadata is available. Overriding `groupContext['setting_id']` there keeps inline posting flow reusable while ensuring persisted documents use source owner.
Alternative considered: branch ownership logic inside `InlinePosCheckoutPostingAdapter`. Rejected because inline adapter would need to re-derive split intent, duplicating planner responsibilities.

2. Introduce dedicated `PosCheckoutGroupCustomerResolverService`.
Rationale: cross-owner customer resolution has deterministic policy and error semantics that are distinct from posting orchestration. A dedicated resolver isolates policy and enables focused tests.
Alternative considered: resolve customer inline inside split adapter loop. Rejected because it mixes policy, database lookup, and orchestration logic, reducing maintainability.

3. Customer resolution order is selected customer in source setting, then source walk-in customer, else validation failure.
Rationale: keeps cashier-selected customer when valid, provides deterministic fallback for legitimate cross-owner sales, and fails loudly when source data quality is insufficient.
Alternative considered: always force source walk-in for cross-owner groups. Rejected because it discards valid selected customer mapping and reduces customer data fidelity.

4. Keep `InlinePosCheckoutPostingAdapter` document creation mechanics unchanged.
Rationale: this adapter already respects `context['setting_id']` and `context['customer_id']`. Feeding corrected context achieves required behavior with minimal blast radius.
Alternative considered: modify sale reference generation logic directly. Rejected because `Sale::boot()` already provides setting-scoped numbering when ownership is correct.

5. Validation failures for unresolved source customer SHALL expose actionable machine-readable payload.
Rationale: rollout will reveal source settings missing walk-in customers or customer records. Explicit `error_code` and reason metadata improve operator triage and pre-deploy audits.
Alternative considered: generic `CUSTOMER_UNRESOLVED` without detail. Rejected because it slows diagnosis in multi-setting operations.

## Risks / Trade-offs

- [Missing source walk-in customer configuration causes new finalize failures] -> enforce rollout checklist and include actionable error details (`source_setting_id`, `terminal_setting_id`, `selected_customer_id`).
- [Downstream reports assume transaction ownership equals terminal setting] -> run mixed-owner report regression checks in staging before production rollout.
- [Cross-setting customer lookup adds query overhead] -> keep resolver lookups scoped to involved settings and reuse loaded setting/customer data per finalize execution.
- [Behavior drift between split adapter and tests] -> add feature tests asserting ownership, numbering prefix, transaction ownership, and unresolved-customer failure path.

## Migration Plan

1. Implement split adapter context override and resolver integration behind existing split-posting feature flag behavior.
2. Add/extend feature tests for mixed-owner split checkout ownership, numbering, idempotent replay stability, and source-customer failure diagnostics.
3. Validate staging with carts spanning 2+ source settings and confirm source settings have `pos_walk_in_customer_id`.
4. Deploy as standard application release; no schema migration required.
5. Rollback by reverting this change set if production shows unexpected ownership or finalize failure regressions.

## Open Questions

- Should unresolved customer diagnostics be surfaced verbatim to all API clients or redacted for non-internal roles while retaining full logs?
- Do we need a preflight admin audit command for missing `pos_walk_in_customer_id` across borrow-enabled source settings in a follow-up change?
