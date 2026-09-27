## 1. UI Implementation

- [x] 1.1 Add `#pos-payment-confirmation-modal` HTML structure to `Modules/Pos/Resources/views/sell/modals/staged_checkout.blade.php`.
- [x] 1.2 Include placeholders in the modal for Method, Entered Amount, Remaining Balance, and the dynamic Alert container.
- [x] 1.3 Add "Lanjutkan" (Proceed) and "Batal" (Cancel) buttons with distinct IDs.

## 2. State Machine Integration

- [x] 2.1 In `public/js/pos-staged-payment.js`, intercept the submission in `submitStagePayment` after local validation succeeds, but before calling the backend.
- [x] 2.2 Populate the confirmation modal elements with the currently selected payment method, entered amount, and remaining balance.

## 3. Warning Logic and Alerts

- [x] 3.1 Implement logic in JS to calculate the difference between the entered amount and remaining balance.
- [x] 3.2 Inject the appropriate alert block into the confirmation modal (Success for match, Info for overpayment/change, Warning/Danger for underpayment/receivable).
- [x] 3.3 Programmatically show the confirmation modal.

## 4. Final Submission Handlers

- [x] 4.1 Bind the "Batal" button to dismiss the confirmation modal and leave the user at the payment input form.
- [x] 4.2 Bind the "Lanjutkan" button to dismiss the confirmation modal, set processing state, and execute the actual backend POST request that was originally at the end of `submitStagePayment`.
