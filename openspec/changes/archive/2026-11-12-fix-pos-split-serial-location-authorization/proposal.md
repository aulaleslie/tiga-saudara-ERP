# Proposal

## Why

Valid cross-business POS transactions can fail payment because split posting rechecks serial location access against the stock owner's sales locations instead of the originating POS business's locations. Transaction TNC-TXN-2026-11-1064 demonstrates this: TNC permits location 2, containing serial XE4Q217454, while TOP IT disables that location for its own POS.

## What Changes

- Preserve the originating POS business identity through split posting and use it for final serial source-location authorization.
- Apply the same rule to serialized parent lines and bundle components, retaining locked current-state checks.
- Keep sale ownership, dispatch, inventory, customer resolution, tax, and payment allocation aligned with their existing source-owner rules.
- Add focused success and rejection regression coverage; no transaction edits, location-setting changes, schema migrations, or new UI.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-checkout-serial-stock-validation`: Explicitly require final serial location authorization to use the originating POS business even after owner grouping.

## Impact

The change is limited to `SplitPosCheckoutPostingAdapter`, `InlinePosCheckoutPostingAdapter`, and focused existing POS feature tests. Existing inline callers retain a fallback to their current setting context. Existing saved drafts remain usable without rewriting data. No new dependencies or public API changes are expected.
