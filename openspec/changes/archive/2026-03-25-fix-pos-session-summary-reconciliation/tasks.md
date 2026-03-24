## 1. Checkout Finalization Fixes

- [x] 1.1 Correct `actualChangeTotal` calculation in `FinalizePosCheckoutService@postCheckout` to use `max(0, $paidTotal - $actualGrandTotal)`.
- [x] 1.2 Verify and ensure `EVENT_CHANGE_OUT` cash event is correctly recorded for multi-payment checkouts when change is given.

## 2. Session Summary Improvements

- [x] 2.1 Update `PosSessionSummaryService@getSummary` to load `payments.paymentMethod` for checkouts.
- [x] 2.2 Aggregate unique payment method names into a comma-separated string for the "Metode" column in the summary payload.

## 3. Verification & Testing

- [x] 3.1 Run `POSCheckoutMultiPaymentFinalizeTest.php` to verify the fix for change calculation and cash event recording.
- [x] 3.2 Add a test case to `POSSessionSummaryViewTest.php` to verify that transactions with multiple payments correctly display all methods.
- [x] 3.3 Run `POSExpectedCashCalculatorTest.php` to ensure the overall cash reconciliation logic remains sound.
- [x] 3.4 Verify the "Expected Cash" (Ekspektasi Kas) on the session summary page correctly reflects the net cash after change.
