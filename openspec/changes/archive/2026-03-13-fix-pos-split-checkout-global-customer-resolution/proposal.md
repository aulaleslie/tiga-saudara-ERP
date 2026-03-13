## Why

Split checkout can fail with `CUSTOMER_UNRESOLVED` even when the selected customer exists, because split-specific resolution incorrectly requires `customers.setting_id` to match each source setting. This conflicts with current POS behavior where customer identity is global by `customers.id`, and it blocks valid mixed-owner split transactions.

## What Changes

- Update split-group customer resolution to treat customers as globally resolvable by `customers.id`.
- Remove source-setting ownership filters from split resolver fallback paths (selected customer and walk-in customer lookup).
- Update inline split posting customer validation to require global customer existence by ID, not source-setting ownership.
- Preserve split ownership behavior (`sales.setting_id`, transaction ownership, numbering prefix, and idempotent replay behavior).
- Add/adjust regression coverage so failures only occur when no valid customer record can be resolved by ID.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-checkout-split-posting`: Customer resolution for split checkout posting must validate customer existence globally by ID, while preserving source-setting ownership for posting and numbering.

## Impact

- Affected code:
  - `Modules/Pos/Services/PosCheckoutGroupCustomerResolverService.php`
  - `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`
  - `Modules/Pos/Tests/Feature/POSCheckoutSplitPostingTest.php`
  - Potentially related split/idempotency test coverage under `Modules/Pos/Tests/Feature/`
- API/runtime behavior:
  - Fewer false unresolved-customer failures in mixed-owner split scenarios.
  - No intended change to ownership attribution, totals, numbering, or idempotency semantics.
- Dependencies/systems:
  - No new external dependencies.
  - Requirement/spec updates under OpenSpec for split posting behavior.
