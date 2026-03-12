## 1. Split Group Ownership Propagation

- [x] 1.1 Update `SplitPosCheckoutPostingAdapter` to set per-group posting owner context (`setting_id = source_setting_id`) before calling inline posting.
- [x] 1.2 Ensure posted group results persist ownership correctly by validating `sales.setting_id` and stock `transactions.setting_id` follow each group source owner.
- [x] 1.3 Keep split summary reconciliation and legacy finalize compatibility projection (`sale_id`, `sale_payment_id`, `dispatch_ids`) unchanged after ownership propagation.

## 2. Source-Group Customer Resolution

- [x] 2.1 Implement `PosCheckoutGroupCustomerResolverService` with deterministic precedence: selected source customer, then source walk-in customer, else unresolved.
- [x] 2.2 Integrate resolver output into split-group posting context (`customer_id`) and ensure inline posting receives owner-correct customer for each group.
- [x] 2.3 Add actionable unresolved-customer failure payload (`CUSTOMER_UNRESOLVED`, `SOURCE_CUSTOMER_UNRESOLVED`, source/terminal/selected identifiers) in finalize flow.

## 3. Regression Coverage

- [x] 3.1 Extend split posting feature tests with mixed-owner fixtures (2+ source settings) and assert each group sale ownership matches `source_setting_id`.
- [x] 3.2 Add assertions that per-group sale references use owning setting numbering/prefix and prevent cross-setting sequence leakage.
- [x] 3.3 Add failure-path test for unresolved source customer and verify idempotent replay still returns canonical split mapping without duplicate posting.

## 4. Verification and Rollout Readiness

- [x] 4.1 Run targeted POS test suite for split posting, finalize idempotency, and related source-tax behavior.
- [x] 4.2 Prepare/refresh staging checklist for mixed-owner checkout validation, including source walk-in customer prerequisites.
