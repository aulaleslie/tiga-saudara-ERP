## 1. Split Group Resolver Update

- [x] 1.1 Update `PosCheckoutGroupCustomerResolverService` to resolve selected checkout customer by global `customers.id` without `setting_id` ownership filtering.
- [x] 1.2 Update source walk-in fallback lookup to resolve by configured `pos_walk_in_customer_id` existence only, and keep unresolved error payload details actionable.
- [x] 1.3 Ensure unresolved flow is triggered only when both selected and fallback customer IDs cannot resolve to existing customer records.

## 2. Inline Posting Validation Update

- [x] 2.1 Update `InlinePosCheckoutPostingAdapter` customer lookup to validate global customer existence by ID only.
- [x] 2.2 Confirm split posting ownership attribution (`sales.setting_id`, transaction ownership, numbering context) remains sourced from split group owner setting.

## 3. Regression Test Coverage

- [x] 3.1 Update split posting regression test(s) that currently expect source-setting ownership mismatch to fail with unresolved customer error.
- [x] 3.2 Add failure-path test where selected customer and source walk-in fallback are both missing/invalid, expecting `CUSTOMER_UNRESOLVED`.
- [x] 3.3 Add/adjust assertions proving cross-owner customer scenarios still post with correct source-setting ownership, numbering behavior, and idempotent replay outcomes.

## 4. Verification

- [x] 4.1 Run targeted POS split posting and idempotency tests and fix any regressions.
- [x] 4.2 Validate staging mixed-owner scenarios for selected customer, walk-in fallback, and truly unresolved customer paths.
