# POS Split Posting Ownership Staging Checklist

Date: 2026-03-12  
Scope: validate split-posting ownership and customer fallback behavior before production rollout.

## 1. Configuration Prerequisites

- [ ] `POS_CHECKOUT_SPLIT_POSTING_ENABLED=true` in staging runtime config.
- [ ] All source settings used by terminal sale-location assignments have valid `settings.pos_walk_in_customer_id`.
- [ ] Terminal settings used for checkout have at least one enabled payment method.
- [ ] Borrowed sale locations are configured and enabled in `setting_sale_locations`.

## 2. Functional Validation

- [ ] Run a mixed-owner checkout from terminal setting A with at least one line sourced from setting A and one from setting B.
- [ ] Confirm finalize response contains `split_groups`, `sales`, and `sale_payments` with deterministic ordering.
- [ ] Confirm backward-compatible fields (`sale_id`, `sale_payment_id`, `dispatch_ids`) still map to first deterministic group.
- [ ] Confirm each split group sale is persisted under `sales.setting_id == split_groups[*].source_setting_id`.
- [ ] Confirm each stock movement row is persisted under `transactions.setting_id == split_groups[*].source_setting_id`.
- [ ] Confirm each sale reference prefix/sequence follows the owning setting’s numbering config.

## 3. Failure-Path Validation

- [ ] Remove or invalidate source setting walk-in customer for one borrowed source setting.
- [ ] Finalize a mixed-owner checkout where selected customer is only valid in terminal setting.
- [ ] Confirm response returns `422` with `code=CUSTOMER_UNRESOLVED`.
- [ ] Confirm response details include:
  - `reason_code=SOURCE_CUSTOMER_UNRESOLVED`
  - `source_setting_id`
  - `terminal_setting_id`
  - `selected_customer_id`
- [ ] Confirm failed checkout record stores `failure_code=CUSTOMER_UNRESOLVED`.

## 4. Regression/Idempotency Validation

- [ ] Replay the same finalize idempotency key after a successful mixed-owner checkout.
- [ ] Confirm replay returns `200`, `idempotent_replay=true`, and identical split map payload.
- [ ] Confirm no duplicate `sales`, `sale_payments`, `dispatches`, or `pos_checkout_sales` are created.

## 5. Test Execution Notes (2026-03-12)

- `php artisan test Modules/Pos/Tests/Feature/POSCheckoutSplitPostingTest.php` -> PASS (3 tests)
- `php artisan test Modules/Pos/Tests/Feature/POSCheckoutFinalizeIdempotencyTest.php` -> PASS (11 tests)
- `php artisan test Modules/Pos/Tests/Feature/POSTaxBySourceSnapshotTest.php` -> FAIL (1 test: `test_mixed_tax_outcomes_in_split_allocation`, existing behavior outside this change scope)
