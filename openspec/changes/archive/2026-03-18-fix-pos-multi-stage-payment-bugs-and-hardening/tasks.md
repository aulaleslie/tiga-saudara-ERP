## 1. Fix Remainder Calculation Bug

- [x] 1.1 Update paymentChain object to track original_grand_total separately from remainder
- [x] 1.2 Modify submitStagePayment() to send original grand total instead of (remainder + amount)
- [x] 1.3 Update initializeNewPaymentChain() to preserve original_grand_total value
- [x] 1.4 Update checkReloadRecovery() to restore original_grand_total from session
- [x] 1.5 Test: Submit non-cash payment (40,000 of 60,000 total) and verify remainder = 20,000
- [x] 1.6 Test: Complete multi-stage payment and verify final remainder = 0

## 2. Implement Method-Specific Amount Validation

- [x] 2.1 Create validateAmountForMethod() function that checks is_cash flag
- [x] 2.2 Implement cash validation rule: amount >= remainder (allow overpayment)
- [x] 2.3 Implement non-cash validation rule: amount <= remainder (no overpayment)
- [x] 2.4 Update updateStageValidation() to call new validation function
- [x] 2.5 Update error messages to be specific to payment method type
- [x] 2.6 Test: Cash payment underpayment is rejected with proper message
- [x] 2.7 Test: Cash payment overpayment is accepted (e.g., 120,000 of 100,000)
- [x] 2.8 Test: Non-cash payment overpayment is rejected with proper message
- [x] 2.9 Test: Non-cash payment exact amount is accepted

## 3. Improve EDC Reference Validation

- [x] 3.1 Verify existing EDC reference validation in validateBeforeSubmit()
- [x] 3.2 Ensure error message is clear: "Nomor referensi EDC wajib diisi"
- [x] 3.3 Ensure EDC reference field gets focus when error occurs
- [x] 3.4 Test: Non-cash method with requires_reference=true without reference is rejected
- [x] 3.5 Test: Non-cash method with requires_reference=true with valid reference is accepted

## 4. Add Visual Feedback for Payment Method Selection

- [x] 4.1 Add background-color styling to payment method input in sell.blade.php (staged-method-search)
- [x] 4.2 Verify form-control class is applied for consistent Bootstrap styling
- [x] 4.3 Ensure focus state shows clear outline/border
- [x] 4.4 Test: Payment method input is visually distinct from modal background
- [x] 4.5 Test: Selected payment method text is clearly visible in input
- [x] 4.6 Test: Focus state shows visual indication (blue outline or border)

## 5. Integration Testing

- [x] 5.1 E2E test: Single-stage cash payment (pay entire amount at once)
- [x] 5.2 E2E test: Two-stage payment (cash + non-cash methods)
- [x] 5.3 E2E test: Cash overpayment with change calculation
- [x] 5.4 E2E test: Reload recovery with existing payment chain
- [x] 5.5 E2E test: Close modal on empty chain (no payments committed)
- [x] 5.6 E2E test: Verify payment chain display shows all committed payments

## 6. Code Quality and Documentation

- [x] 6.1 Review code for clarity and maintainability
- [x] 6.2 Add inline comments explaining grand_total vs remainder logic
- [x] 6.3 Verify no console errors in browser developer tools
- [x] 6.4 Test on multiple payment method configurations (with/without requires_reference)
