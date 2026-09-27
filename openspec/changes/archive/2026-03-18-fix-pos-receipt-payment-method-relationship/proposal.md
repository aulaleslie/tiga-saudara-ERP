## Why

The `PosReceiptService` is trying to eager-load and access a `method()` relationship on `PosCheckoutPayment` that doesn't exist on the model. The model defines the relationship as `paymentMethod()`, causing receipt printing to fail with a `RelationNotFoundException` when multi-payment transactions are finalized. This is a regression from recent multi-payment work where the receipt service wasn't updated to match the actual model definition.

## What Changes

- Update `PosReceiptService::getReceiptData()` to use the correct `paymentMethod()` relationship instead of the non-existent `method()` relationship
- Fix eager-loading statement from `'payments.method'` to `'payments.paymentMethod'`
- Update property access from `$payment->method` to `$payment->paymentMethod` in receipt data assembly

## Capabilities

### New Capabilities
<!-- None - this is a bug fix, not a new capability -->

### Modified Capabilities
<!-- None - no requirement changes, just correcting implementation -->

## Impact

- **Files Modified**: `Modules/Pos/Services/PosReceiptService.php` (3 lines)
- **Affected Systems**: POS receipt printing for multi-payment transactions
- **Testing**: Existing receipt tests should cover this; manual receipt printing should work for multi-payment checkouts
