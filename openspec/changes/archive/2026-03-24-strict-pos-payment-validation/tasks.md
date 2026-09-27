## 1. Backend Validation

- [x] 1.1 Add cash underpayment validation to `stagePayment()` in `PosSellController.php`

## 2. Frontend UX

- [x] 2.1 Update `selectPaymentMethod()` in `pos-staged-payment.js` to show minimum/maximum amount hints below the input
- [x] 2.2 Add HTML element for the hint in the staged payment modal in `sell.blade.php`
- [x] 2.3 Ensure hint updates dynamically if remainder changes or method is re-selected

## 3. Testing

- [x] 3.1 Review `POSPaymentValidationRulesTest.php` and verify it covers the new backend validation for staged payments, or add a specific test for the `stagePayment` endpoint rejecting cash underpayment
