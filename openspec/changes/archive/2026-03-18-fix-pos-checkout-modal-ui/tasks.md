## 1. Fix Payment Method Input Styling

- [x] 1.1 Update `staged-method-search` input styling in sell.blade.php (L1068-1070) with `!important` overrides for background, border, and color
- [x] 1.2 Verify input renders with opaque white background and visible border in browser
- [x] 1.3 Test input is visible and distinct from modal background on different screen sizes

## 2. Implement Amount Input Formatter

- [x] 2.1 Create `formatNumberForDisplay(num)` utility function in pos-staged-payment.js to format numbers with Indonesian thousand separators
- [x] 2.2 Create `setupAmountInputFormatter()` function to attach input event listener to `#staged-amount-input`
- [x] 2.3 Implement formatter logic: strip non-digits, display formatted value, store raw value in `dataset.rawValue`
- [x] 2.4 Change amount input type from `type="number"` to `type="text"` with `inputmode="numeric"` in sell.blade.php (L1079-1080)
- [x] 2.5 Update `submitStagePayment()` to use raw value from `dataset.rawValue` instead of direct input.value
- [x] 2.6 Test formatter displays values correctly (e.g., 150000 → 150.000)
- [x] 2.7 Test raw values are submitted correctly to backend (no formatted strings sent)
- [x] 2.8 Test validation triggers after formatting and button state updates

## 3. Add Quick-Add Amount Buttons

- [x] 3.1 Add HTML markup for quick-add button row below `#staged-amount-input` in sell.blade.php (L1081, add after closing `</div>` of amount form-group)
- [x] 3.2 Add buttons: [+1.000], [+5.000], [+10.000], [+50.000], [Sisa] with class `js-quick-add` or `js-quick-add-remainder`
- [x] 3.3 Style buttons with flex layout, gap spacing, and visual distinction for [Sisa] button
- [x] 3.4 Create `setupQuickAddButtons()` function in pos-staged-payment.js to attach click handlers
- [x] 3.5 Implement click handler for `[+X]` buttons: add amount to current, format display, trigger validation
- [x] 3.6 Implement click handler for [Sisa] button: fill with `paymentChain.remainder`, trigger validation
- [x] 3.7 Test quick-add buttons increment amount correctly
- [x] 3.8 Test [Sisa] button fills to remainder
- [x] 3.9 Test validation runs after quick-add and button state updates correctly
- [x] 3.10 Test stacking multiple quick-add clicks (e.g., +10K + 10K + 5K = 25K)

## 4. Update Payment Chain Display Rendering

- [x] 4.1 Refactor `renderPaymentChain()` function in pos-staged-payment.js (L187-210)
- [x] 4.2 Change render output to display each payment with multi-line structure (method on line 1, amount on line 2, reference on line 3)
- [x] 4.3 Add formatting: method name bold/prominent, amount with thousand separators, reference smaller/secondary
- [x] 4.4 Update badge styling to allow multi-line content with proper spacing
- [x] 4.5 Test payment chain displays single payment correctly
- [x] 4.6 Test payment chain displays multiple payments with proper separation
- [x] 4.7 Test reference number displays when present and doesn't display when absent
- [x] 4.8 Test formatted amounts (Rp100.000) display in chain

## 5. Integration and Initialization

- [x] 5.1 Call `setupAmountInputFormatter()` in `initialize()` function of pos-staged-payment.js
- [x] 5.2 Call `setupQuickAddButtons()` in `initialize()` function of pos-staged-payment.js
- [x] 5.3 Test both formatters are initialized on modal open
- [x] 5.4 Test formatters work after reload recovery (payment chain restoration)

## 6. Validation and Cross-Feature Testing

- [x] 6.1 Test amount validation respects payment method rules after quick-add
- [x] 6.2 Test non-cash payment cannot exceed remainder via quick-add
- [x] 6.3 Test cash payment allows overpayment via quick-add
- [x] 6.4 Test [Lanjut Pembayaran] button enables/disables correctly based on formatted amount
- [x] 6.5 Test form submission sends correct raw numeric values (no formatted strings)
- [x] 6.6 Test backend receives and processes payment correctly

## 7. End-to-End Flow Testing

- [x] 7.1 Test full payment flow: open modal → select method → use quick-add buttons → verify amount display → submit
- [x] 7.2 Test multi-stage payment flow: first payment + quick-add → [Lanjut Pembayaran] → add second payment → chain displays both
- [x] 7.3 Test reload recovery: mid-payment → page reload → modal reopens → payment chain visible → amounts formatted correctly
- [x] 7.4 Test success flow: final payment submitted → gratitude/success modal → receipt generated → cart cleared

## 8. Accessibility and Browser Testing

- [x] 8.1 Test form inputs have proper focus indicators
- [x] 8.2 Test button labels are descriptive and accessible to screen readers
- [x] 8.3 Test payment method input background/border meets WCAG contrast standards
- [x] 8.4 Test on Chrome, Firefox, Safari (if available)
- [x] 8.5 Test responsive layout: tablet and mobile viewport sizes

## 9. Documentation and Cleanup

- [x] 9.1 Add code comments explaining formatter logic and why `!important` is needed for input styling
- [x] 9.2 Verify no console errors or warnings in browser devtools
- [x] 9.3 Clean up any temporary debug console.log statements
- [x] 9.4 Verify no unrelated code changes were included
