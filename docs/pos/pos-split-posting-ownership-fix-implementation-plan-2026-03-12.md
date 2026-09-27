# POS Split Posting Ownership Fix Implementation Plan

Date: 2026-03-12  
Owner: POS Team  
Type: implementation plan only (no code in this document)

## Objective

Fix split checkout posting so each generated sale is owned by its true source business (`source_setting_id`), with correct per-business sale numbering/prefix, while keeping one POS checkout under terminal business.

## Problem Summary

Current behavior with `POS_CHECKOUT_SPLIT_POSTING_ENABLED=true`:
- Split groups are generated correctly (`source_setting_id` in `split_groups` / `pos_checkout_sales` is correct).
- Actual posted sales still use terminal setting ownership.
- Result: all `sales.setting_id` and sale references are generated under terminal business.

Confirmed root-cause area:
- `SplitPosCheckoutPostingAdapter` reuses original checkout context and does not override group-level owner setting before calling inline posting.
- `InlinePosCheckoutPostingAdapter` persists `Sale` and `Transaction` using `context['setting_id']`.

## Locked Decisions

1. Keep `pos_checkouts.setting_id` as terminal business (cashier session owner).
2. Post each split group with owner setting = `group.source_setting_id`.
3. Sale numbering must follow existing `Sale::boot()` behavior per `sales.setting_id` (no custom numbering logic).
4. Customer resolution for cross-owner split groups uses deterministic fallback:
- Use selected customer if it exists in source setting.
- Else use source setting walk-in customer (`settings.pos_walk_in_customer_id`) if available.
- Else fail finalize with actionable validation error.
5. Keep finalize response backward-compatible (`sale_id`, `sale_payment_id`, `dispatch_ids` still point to first group).

## Scope

In scope:
1. Ownership propagation for split posting groups.
2. Cross-setting customer resolution for each posted group.
3. Correct ownership for `sales` and stock `transactions` generated from split groups.
4. Regression tests covering multi-business mixed cart scenarios (2+ source businesses).
5. Checkout diagnostics improvement for source-customer failures.

Out of scope:
1. New cross-tenant master-customer mapping model.
2. Checkout UI redesign.
3. Payment split redesign.

## Target Code Areas

1. `Modules/Pos/Services/Adapters/SplitPosCheckoutPostingAdapter.php`
- Inject/resolve source-setting posting context per group.
- Resolve per-group customer before calling inline adapter.

2. `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`
- Consume group-level context safely (owner setting + customer).
- Keep document creation behavior unchanged, but now fed with correct context.

3. `Modules/Pos/Services/FinalizePosCheckoutService.php`
- Ensure actionable validation payload when source customer cannot be resolved.

4. (new) customer resolver service (recommended)
- `Modules/Pos/Services/PosCheckoutGroupCustomerResolverService.php`
- Single responsibility: choose customer ID for `(terminal_setting, source_setting, selected_customer_id)`.

5. Tests:
- `Modules/Pos/Tests/Feature/POSCheckoutSplitPostingTest.php`
- New targeted feature tests for mixed-owner ownership and numbering.

## Phase Plan

### Phase 1 - Group Ownership Propagation

Deliverables:
1. In split adapter loop, set posting context owner to current group owner (`source_setting_id`) before calling inline adapter.
2. Preserve current grouped lines/totals/allocations behavior.
3. Preserve current reconciliation and split summary behavior.

Acceptance criteria:
1. For mixed-owner checkout, each `sale_id` in split result maps to `sales.setting_id == split_group.source_setting_id`.
2. `transactions.setting_id` follows source owner for each group line.

### Phase 2 - Source Customer Resolution

Deliverables:
1. Add deterministic resolver for per-group customer selection.
2. Use resolved source customer ID in group posting context.
3. Add explicit failure code/details for unresolved source customer.

Resolution policy (implementation contract):
1. If selected customer belongs to source setting: use it.
2. Else if source setting walk-in customer exists: use walk-in.
3. Else throw validation exception with details:
- `error_code`: `CUSTOMER_UNRESOLVED`
- `details.reason_code`: `SOURCE_CUSTOMER_UNRESOLVED`
- include `source_setting_id`, `terminal_setting_id`, `selected_customer_id`.

Acceptance criteria:
1. Mixed-owner checkout succeeds even when selected terminal customer is not present in source setting but source walk-in exists.
2. Checkout fails with actionable error details when neither selected nor walk-in customer is valid for source setting.

### Phase 3 - Numbering and Ownership Validation

Deliverables:
1. Verify no custom numbering path is needed; rely on existing `Sale` model reference generation.
2. Add assertions ensuring each source business generates references with its own setting prefix/sequence.

Acceptance criteria:
1. Mixed-owner split creates sale references according to each owner setting configuration.
2. No cross-setting reference leakage in the same checkout.

### Phase 4 - Regression Test Coverage

Deliverables:
1. Extend split posting feature tests with true cross-setting ownership fixture:
- Terminal setting A.
- Borrowed allowed locations from setting B and optionally setting C.
- Cart lines allocating to A/B(/C).
2. Add assertions:
- `split_groups` count and totals reconcile.
- `sales.setting_id` per split group is correct.
- `sales.reference` prefix corresponds to owning setting.
- `transactions.setting_id` per movement matches source owner.
- Idempotency replay returns identical split map and no duplicate posting.
3. Add failure-path test for unresolved source customer.

Acceptance criteria:
1. Ownership regression is reproducibly caught by automated tests.
2. Existing checkout idempotency and reconciliation tests remain green.

### Phase 5 - Rollout and Verification

Deliverables:
1. Staging validation script/checklist for mixed-owner carts (2 and >2 source businesses).
2. Production readiness checklist:
- `POS_CHECKOUT_SPLIT_POSTING_ENABLED=true`
- source settings have valid walk-in customer configured.
3. Post-deploy smoke checks on latest posted split checkouts.

Acceptance criteria:
1. Real mixed-owner checkout posts one sale per owner with correct owner and numbering.
2. No increase in `CUSTOMER_UNRESOLVED` beyond expected data-quality cases.

## Data and Operational Prerequisites

1. Every source setting that can be borrowed in sale-location config must have `pos_walk_in_customer_id` set.
2. Sale-location assignments remain source-of-truth for allowed borrowed stock.
3. Split posting flag remains enabled in target environment.

## Risks and Mitigations

1. Risk: checkout fails after ownership fix due to missing source customer.
- Mitigation: enforce walk-in fallback + pre-rollout audit of `pos_walk_in_customer_id` per source setting.

2. Risk: hidden assumptions in downstream reporting expect `transactions.setting_id == terminal setting`.
- Mitigation: run targeted report regression checks using mixed-owner checkout fixtures.

3. Risk: incomplete test fixture still uses single-owner stock, missing cross-owner behavior.
- Mitigation: add dedicated multi-setting fixtures and ownership assertions.

## Suggested Test Execution Set

1. `php artisan test Modules/Pos/Tests/Feature/POSCheckoutSplitPostingTest.php`
2. `php artisan test Modules/Pos/Tests/Feature/POSCheckoutFinalizeIdempotencyTest.php`
3. `php artisan test Modules/Pos/Tests/Feature/POSTaxBySourceSnapshotTest.php`
4. New ownership-focused feature test file(s) for mixed-owner + numbering assertions.

## Exit Criteria

1. Mixed-owner checkout from terminal setting A creates split sales under the correct owner settings (A/B/C...).
2. Sale references for each split sale follow owner setting prefix/sequence.
3. Inventory transactions for each split line are owned by source setting.
4. Idempotent replay remains stable and does not duplicate documents.
5. Automated tests cover and enforce ownership correctness for 2+ source businesses.
