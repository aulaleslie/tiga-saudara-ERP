## 1. Refine Split Planner Logic

- [x] 1.1 Update `PosCheckoutSplitPlannerService::plan` to check for `parent_qty == 0`.
- [x] 1.2 Set `groupLine['qty'] = 0` and `groupLine['unit_price'] = 0` when `parent_qty == 0`.
- [x] 1.3 Ensure `groupLine['line_subtotal']` and `groupLine['line_tax_total']` still reflect bundle item contributions.

## 2. Enhance POS Posting Adapter

- [x] 2.1 Update validation in `InlinePosCheckoutPostingAdapter::post` to allow `qty = 0` for sale detail rows.
- [x] 2.2 Update `InlinePosCheckoutPostingAdapter::post` to use `line['line_tax_total']` instead of recalculating tax from parent allocations.
- [x] 2.3 Implement revenue calculation for `SaleBundleItem` by summing `allocated_minor` from its specific child allocations.
- [x] 2.4 Persist `SaleBundleItem` with actual `price`, `sub_total`, and `quantity` from allocations.

## 3. Verification

- [x] 3.1 Create a Feature Test in `Modules/Pos/Tests/Feature/SplitBundleTransactionTest.php` covering:
    - Single owner bundle (baseline).
    - Multi-owner split (one owner provides parent + components, another provides only components).
    - Verification of sale details and bundle items in the DB.
- [x] 3.2 Manually verify the "Sales Show" view displays the new bundle item prices correctly.
