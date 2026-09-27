## Context

The POS system currently uses a staged checkout flow (`pos-staged-payment.js`) where cashiers sequentially add payments to cover a transaction's grand total. Once the remaining balance is zero or the cashier submits a payment, the transaction is finalized. However, there is no final confirmation step, meaning a typo in the amount input can result in immediate, erroneous transaction processing (e.g., severe under-collection or massive change calculation). 

To solve this, we are introducing a confirmation modal that pauses the submission, giving the cashier a clear breakdown of the payment amount versus the remaining balance, with explicit warnings for edge cases.

## Goals / Non-Goals

**Goals:**
- Provide a native Bootstrap confirmation modal intercepting the staged checkout submission.
- Display a clear summary of the payment: method, entered amount, and remaining balance.
- Dynamically inject warning alerts based on whether the payment creates a change (kembalian), perfectly matches (pas), or is insufficient (kurang/piutang).
- Prevent accidental finalization of erroneous payment amounts.

**Non-Goals:**
- This is NOT a supervisor approval flow. No PIN or permissions check is required.
- Do not alter the underlying checkout calculation logic, only the UI sequence.

## Decisions

- **Client-Side Interception**: The confirmation modal logic will live entirely within `pos-staged-payment.js`. Instead of directly calling the backend when `#staged-payment-submit` is clicked, the script will validate the input, populate the `#pos-payment-confirmation-modal`, and show it. 
- **Modal HTML Placement**: The HTML for the confirmation modal will be placed in `Modules/Pos/Resources/views/sell/modals/staged_checkout.blade.php` alongside the main staged checkout modal to keep related UI components together.
- **Dynamic Warning Logic**: 
  - `amount == remainder`: Green/Success alert.
  - `amount > remainder`: Info/Primary alert showing the change amount.
  - `amount < remainder`: Warning/Danger alert showing the unpaid balance.

## Risks / Trade-offs

- **Risk:** The additional click adds friction to the checkout process.
  - **Mitigation:** The visual clarity and error prevention far outweigh the micro-friction. The design of the modal should make it extremely quick to read and confirm (e.g., prominent "Lanjutkan" button, Enter key binding if possible).
- **Risk:** State mismatch if the cart changes behind the modal.
  - **Mitigation:** The modal is purely visual and relies on the already-validated state inside the staged checkout modal. If canceled, the state remains intact.
