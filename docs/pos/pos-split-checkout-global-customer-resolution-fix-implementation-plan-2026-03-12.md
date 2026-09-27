# POS Split Checkout Global Customer Resolution Fix Implementation Plan

Date: 2026-03-12
Owner: POS Team
Type: implementation plan only (no code in this document)

## Objective

Fix split checkout customer resolution so customer identity is treated as global across businesses, while keeping split sale and stock ownership posted by `source_setting_id`.

## Reported Symptom

Split checkout fails with:

`Customer could not be resolved for split checkout source setting.`

## Investigation Findings

### Why this happens

1. Split-group customer resolver hard-checks customer ownership by source setting.
- File: `Modules/Pos/Services/PosCheckoutGroupCustomerResolverService.php`
- Current logic enforces:
  - selected customer must match `where('setting_id', $sourceSettingId)`
  - source walk-in must match `where('setting_id', $sourceSettingId)`
- If both fail, it throws `CUSTOMER_UNRESOLVED` with `SOURCE_CUSTOMER_UNRESOLVED`.

2. Inline posting has a second hard-check that also requires customer ownership by posting setting.
- File: `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`
- Current customer lookup enforces `where('setting_id', $settingId)` before sale creation.

### Why this conflicts with current POS customer behavior

POS customer selection and validation are global by customer ID, not source-setting scoped:
- `Modules/Pos/Http/Requests/UpdatePosCartCustomerRequest.php` validates only `exists:customers,id`.
- `Modules/Pos/Services/PosCheckoutCustomerResolverService.php` resolves selected customer by `whereKey(...)` only.
- `Modules/Pos/Services/PosCustomerSearchService.php` searches customers globally.

Conclusion: split posting customer resolution currently uses stricter ownership rules than the rest of POS customer flow, creating this regression.

## Target Behavior (Post-Fix)

1. Customer is global for checkout posting.
2. Split sale ownership remains by `source_setting_id` (unchanged).
3. Customer resolution precedence for each split group:
- selected checkout customer (global `customers.id` existence)
- else source setting `pos_walk_in_customer_id` (global `customers.id` existence)
- else fail with `CUSTOMER_UNRESOLVED`.
4. Finalize should only fail when no valid customer record can be resolved by ID, not because of `customers.setting_id` mismatch.

## Implementation Scope

In scope:
1. Remove source-setting ownership filters from split-group customer resolution.
2. Remove posting-setting ownership filters from inline posting customer lookup.
3. Update split posting regression tests to reflect global-customer rule.
4. Keep split ownership, numbering, and idempotency behavior unchanged.

Out of scope:
1. Customer master-data redesign.
2. Checkout UI redesign.
3. Data migration for customer records.

## Phase Plan

### Phase 1 - Resolver Policy Update

Deliverables:
1. Update `PosCheckoutGroupCustomerResolverService`:
- Selected customer lookup by ID only.
- Walk-in lookup by ID only (after reading `source_setting_id` configuration).
- Preserve actionable error payload on unresolved cases.

Acceptance criteria:
1. Split group resolves selected customer even when `customers.setting_id != source_setting_id`.
2. Split group resolves source walk-in customer even when that customer row is not owned by source setting.

### Phase 2 - Inline Posting Customer Validation Update

Deliverables:
1. Update `InlinePosCheckoutPostingAdapter` customer lookup to validate global customer existence by ID.
2. Keep sale/transaction owner setting behavior unchanged (`setting_id` still sourced from posting context).

Acceptance criteria:
1. Inline posting does not reject valid global customer IDs due to owner mismatch.
2. `sales.setting_id` and `transactions.setting_id` continue to follow split group owner context.

### Phase 3 - Regression Test Updates

Deliverables:
1. Update split failure-path test currently asserting `SOURCE_CUSTOMER_UNRESOLVED` due source-setting mismatch.
- Replace with success assertion when selected global customer exists.
2. Add failure-path test for truly unresolved customer:
- selected customer missing/invalid
- and source walk-in missing or points to non-existent customer
- expect `CUSTOMER_UNRESOLVED` with actionable details.
3. Add/adjust assertion to ensure cross-owner split still posts:
- correct `sales.setting_id` per group
- correct source setting numbering prefix
- no duplicate posting on idempotent replay.

Acceptance criteria:
1. Test suite catches any reintroduction of `setting_id`-based customer blocking.
2. Existing split ownership guarantees remain green.

### Phase 4 - OpenSpec Alignment

Deliverables:
1. Create follow-up OpenSpec change to revise completed requirement text that currently mandates source-setting customer membership.
2. Update proposal/design/spec/tasks language from "customer must exist in source setting" to "customer must exist globally by ID".

Acceptance criteria:
1. OpenSpec artifacts match implemented domain rule: customers are global across businesses.

### Phase 5 - Staging Verification

Deliverables:
1. Validate mixed-owner split checkout where selected customer belongs to terminal business and source business has no matching customer ownership.
2. Validate scenario where source walk-in points to a globally valid customer owned by another business.
3. Verify unresolved path still works when no valid customer record exists.

Acceptance criteria:
1. No false `SOURCE_CUSTOMER_UNRESOLVED` for valid global customer IDs.
2. Ownership and totals reconciliation remain correct for split posting.

## Risks and Mitigations

1. Risk: expanding customer acceptance across businesses may expose assumptions in reporting.
- Mitigation: verify downstream reports use `sales.setting_id` for ownership and `customer_id` only as reference.

2. Risk: stale `pos_walk_in_customer_id` references (deleted customer rows) still cause checkout failures.
- Mitigation: keep actionable error details and add periodic config audit for invalid walk-in IDs.

3. Risk: old tests still encode source-setting customer ownership assumptions.
- Mitigation: explicitly rewrite/rename affected tests to global-customer semantics.

## Suggested Validation Commands

1. `php artisan test Modules/Pos/Tests/Feature/POSCheckoutSplitPostingTest.php`
2. `php artisan test Modules/Pos/Tests/Feature/POSCheckoutFinalizeIdempotencyTest.php`
3. `php artisan test Modules/Pos/Tests/Feature/POSTaxBySourceSnapshotTest.php`

## Exit Criteria

1. Split checkout succeeds with valid global customer IDs regardless of customer owner setting.
2. Split sale ownership and numbering remain source-setting-correct.
3. Unresolved customer failures occur only for genuinely invalid/missing customer IDs.
4. OpenSpec artifacts are updated to reflect global customer policy.
