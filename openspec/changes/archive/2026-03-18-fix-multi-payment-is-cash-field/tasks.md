## 1. Fix normalizeMultiPayment() Method

- [x] 1.1 Extract `is_cash` from the first normalized payment in `normalizeMultiPayment()`
- [x] 1.2 Add `is_cash` field to the returned payment structure at root level
- [x] 1.3 Verify the returned structure now includes all required fields: `is_multi_payment`, `payments`, `amount_paid`, `total_cash_minor_units`, `canonical_payment_hash`, `payment_method_id`, `reference`, and `is_cash`

## 2. Verify No Breakage in Related Code

- [x] 2.1 Check `payloadHash()` method still works with the updated payment structure
- [x] 2.2 Verify `validateCartAndPayment()` handles multi-payment with `is_cash` present
- [x] 2.3 Ensure `postCheckout()` method accesses `is_cash` without errors for multi-payment checkouts
- [x] 2.4 Confirm cash event recording (`PosSessionCashEvent::query()->create()`) logic works for multi-payment

## 3. Run Tests

- [x] 3.1 Run all multi-payment finalization tests to verify "Undefined array key 'is_cash'" errors are resolved
- [x] 3.2 Run full POS test suite to ensure no regressions in single-payment path
- [x] 3.3 Verify multi-payment split allocation tests pass
