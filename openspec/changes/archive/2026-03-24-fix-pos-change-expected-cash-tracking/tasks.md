## 1. Add EVENT_CHANGE_OUT Constant

- [x] 1.1 Add `EVENT_CHANGE_OUT = 'CHANGE_OUT'` constant to PosSessionCashEvent entity class
- [x] 1.2 Verify constant is properly exported and accessible in services

## 2. Implement Change Outflow Event Creation

- [x] 2.1 In FinalizePosCheckoutService.postCheckout(), after CASH_SALE_IN event creation (line ~697), add logic to create CHANGE_OUT event when $actualChangeTotal > 0
- [x] 2.2 Create CHANGE_OUT event with: event_type = EVENT_CHANGE_OUT, direction = DIRECTION_OUT, amount = $actualChangeTotal, reference_type = 'pos_checkout', reference_id = $checkoutId
- [x] 2.3 Ensure CHANGE_OUT event creation happens within same transaction as CASH_SALE_IN (already in DB::transaction block)
- [x] 2.4 Update session.expected_cash_total by subtracting $actualChangeTotal when CHANGE_OUT event is created

## 3. Verify Expected Cash Calculation

- [x] 3.1 Confirm PosSessionExpectedCashCalculator already handles DIRECTION_OUT correctly (review lines 56-59)
- [x] 3.2 Confirm PosSessionExpectedCashCalculator will not throw exception for EVENT_CHANGE_OUT event type (review unknown direction handling at lines 66-72)
- [x] 3.3 Add test to verify CHANGE_OUT events are processed correctly in expected cash total

## 4. Test Coverage

- [x] 4.1 Write test: Single-payment cash with change creates CHANGE_OUT event
- [x] 4.2 Write test: Multi-payment (cash + non-cash) with change creates correct CHANGE_OUT amount
- [x] 4.3 Write test: Expected cash calculation with change event: opening (2M) + cash (5M) - change (1M) = 6M
- [x] 4.4 Write test: No CHANGE_OUT event when payment equals grand total (zero change)
- [x] 4.5 Write test: No CHANGE_OUT event for non-cash-only payments
- [x] 4.6 Write test: Session summary includes CHANGE_OUT events in cash_events timeline

## 5. Verify Expected Cash Display

- [x] 5.1 Confirm session index "Kas" column shows expected_cash_total for OPEN sessions (already correct, review session/index.blade.php lines 92-96)
- [x] 5.2 Verify Kas column now shows correct value (e.g., 6M instead of 7M) for sessions with change

## 6. Integration Verification

- [x] 6.1 Run full POS test suite to ensure no regressions
- [x] 6.2 Verify existing tests for multi-payment checkouts still pass (POSCheckoutMultiPaymentFinalizeTest.php)
- [x] 6.3 Check that safe drop tests still work correctly with change tracking (PosSafeDropService)

## 7. Documentation & Cleanup

- [x] 7.1 Add comment in FinalizePosCheckoutService explaining CHANGE_OUT event creation
- [x] 7.2 Verify no temporary debug code or logging changes
- [x] 7.3 Review code style consistency with existing codebase
