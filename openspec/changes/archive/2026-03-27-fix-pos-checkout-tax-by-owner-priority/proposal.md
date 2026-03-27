## Why

POS checkout tax attribution can diverge from source-owner policy in mixed-source carts. This causes incorrect VAT treatment and financial reporting risk, especially when one source owner is PKP and another is non-PKP in the same checkout.

## What Changes

- Enforce a single tax decision rule at posting time: tax inclusion for each posted chunk is determined by the source owner setting (`is_pkp`) and effective tax context, not by inconsistent serial-only heuristics.
- Align serial and non-serial finalize behavior so tax outcomes are deterministic across stock pre-check, split planning, and persisted sale/dispatch records.
- For non-serial taxable lines, prioritize allocation from non-PKP source owners first, then PKP source owners, while preserving configured location order within each owner-priority bucket.
- Add regression coverage for mixed-owner checkout scenarios (serial and non-serial) to prevent tax drift across future changes.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-checkout-split-posting`: Tax and split outcomes for posted groups are tightened so source-owner PKP policy is applied consistently at planning and posting, including mixed-owner carts.
- `pos-checkout-serial-stock-validation`: Non-serial pre-check allocation behavior is updated for taxable lines to follow owner-priority ordering (non-PKP first, then PKP) with stable location-order tie-breaking.

## Impact

- Affected code paths: `Modules/Pos/Services/ResolvePosStockAllocationsService.php`, `Modules/Pos/Services/PosCheckoutSplitPlannerService.php`, `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`, and finalize orchestration in `Modules/Pos/Services/FinalizePosCheckoutService.php`.
- Affected behavior: `sales.tax_amount`, `sale_details.product_tax_amount`, `dispatch_details.tax_id`, and stock bucket decrements (`quantity_non_tax` / `quantity_tax`) in mixed-owner checkout flows.
- Tests to add/adjust: mixed-owner tax assignment and allocation-order feature tests under `Modules/Pos/Tests/Feature/` and planner/allocation unit tests under `Modules/Pos/Tests/Unit/`.
