## Why

Multi-payment finalization is failing with "Undefined array key 'is_cash'" because the `normalizeMultiPayment()` method returns a structure missing the `is_cash` field at the root level. The code expects this field to determine cash drawer behavior, change calculation, and session cash event recording. Without it, all multi-payment checkouts fail immediately after normalization, blocking all sales with multiple payment methods.

## What Changes

- **Fix normalizeMultiPayment()**: Extract `is_cash` from the first payment method and add it to the root level of the returned payment structure, alongside `payment_method_id` and `reference`.
- **Maintain backward compatibility**: Ensure multi-payment structure mirrors single-payment structure where possible, so downstream code doesn't need conditional logic for `is_cash`.
- **Enable checkout finalization**: Allow multi-payment checkouts to proceed through finalization, cash event recording, and session tracking without errors.

## Capabilities

### New Capabilities

None. This is a bug fix within the existing `pos-multi-stage-payment` capability.

### Modified Capabilities

- `pos-multi-stage-payment`: The `normalizeMultiPayment()` method now returns a complete payment structure with all required fields (including `is_cash`) for consistency with single-payment path, ensuring finalization logic can handle both paths uniformly.

## Impact

- **Code**: `Modules/Pos/Services/FinalizePosCheckoutService.php` (normalizeMultiPayment method)
- **Database**: No schema changes required
- **Tests**: All multi-payment finalization tests now pass (they are currently failing with "Undefined array key 'is_cash'")
- **APIs**: No API changes; internal service fix only
- **Systems**: POS checkout finalization, cash drawer events, session cash tracking
