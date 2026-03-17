## 1. Modify Payment Normalization Service

- [x] 1.1 Update `normalizeMultiPayment()` in `FinalizePosCheckoutService` to extract first payment method ID and reference
- [x] 1.2 Add `payment_method_id` and `reference` fields to the return array at root level
- [x] 1.3 Ensure extraction uses the first payment from `$normalized['payments'][0]`

## 2. Verify Ledger Persistence

- [x] 2.1 Confirm `resolveCheckoutLedger()` receives the updated structure with root-level `payment_method_id`
- [x] 2.2 Verify line 403 can access `$payment['payment_method_id']` without errors

## 3. Testing

- [x] 3.1 Run `POSCheckoutMultiPaymentFinalizeTest` to verify multi-payment checkouts finalize successfully
- [x] 3.2 Verify single-payment checkouts still work (backward compatibility)
- [x] 3.3 Confirm `pos_checkout_payments` records are created with correct method IDs and amounts
- [x] 3.4 Verify primary payment method on `pos_checkouts` matches first payment in sequence

## 4. Integration Testing

- [x] 4.1 Test multi-payment checkout with split ownership allocation (3 sales scenario from spec)
- [x] 4.2 Confirm cash event is recorded correctly for multi-payment transactions
- [x] 4.3 Verify receipt generation includes all payment methods
- [x] 4.4 Test idempotent replay with multi-payment payload

## 5. Quality & Documentation

- [x] 5.1 Review code for defensive null checks (first payment must exist after normalization)
- [x] 5.2 Add inline comments explaining primary payment method extraction
- [x] 5.3 Update any related code documentation mentioning payment structure
