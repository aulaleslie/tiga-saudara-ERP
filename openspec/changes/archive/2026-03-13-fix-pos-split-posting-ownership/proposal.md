## Why

Split checkout already computes correct `source_setting_id` per group, but posted sales and inventory transactions still use terminal setting ownership. This produces incorrect business ownership, document numbering, and inventory accounting in mixed-owner carts, so the behavior must be corrected now.

## What Changes

- Propagate split-group owner context (`source_setting_id`) into posting so each generated sale and inventory transaction is owned by its source business.
- Add deterministic per-group customer resolution for cross-owner posting: use selected customer when valid in source setting, otherwise source walk-in customer, otherwise fail with actionable validation details.
- Keep finalize compatibility behavior unchanged (`sale_id`, `sale_payment_id`, `dispatch_ids` remain mapped to first deterministic group).
- Add regression coverage for mixed-owner carts (2+ source settings), including ownership, numbering, transaction ownership, unresolved-customer failure path, and idempotent replay stability.

## Capabilities

### New Capabilities
- `pos-checkout-group-customer-resolution`: Resolve posting customer per split group across terminal/source settings with deterministic fallback and explicit unresolved-customer errors.

### Modified Capabilities
- `pos-checkout-split-posting`: Split posting groups must post under source owner setting, including owner-scoped sale numbering and inventory transaction ownership.

## Impact

- Affected services: `Modules/Pos/Services/Adapters/SplitPosCheckoutPostingAdapter.php`, `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`, `Modules/Pos/Services/FinalizePosCheckoutService.php`, and new resolver service under `Modules/Pos/Services/`.
- Affected persistence behavior: `sales.setting_id`, sale reference prefix/sequence generation, and `transactions.setting_id` for split-posted groups.
- Affected tests: `Modules/Pos/Tests/Feature/POSCheckoutSplitPostingTest.php` and related finalize/idempotency regression coverage.
