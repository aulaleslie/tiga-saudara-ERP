## Why

Currently, when a cashier completes a transaction using the Staged Checkout modal, the payment input fields (such as the nominal amount) are not cleared. When the cashier opens the modal for the next transaction, the nominal from the previous transaction is still prefilled in the input field. This creates a confusing experience and forces the cashier to manually clear the field before entering the correct amount, which slows down the checkout process and risks human error. 

## What Changes

- Add a reset step when opening the staged checkout modal so that previous payment inputs are cleared.
- Ensure the payment method search, EDC reference, and amount fields start fresh for every new checkout session.

## Capabilities

### New Capabilities

### Modified Capabilities
- `pos-staged-payment`: Ensure that form state is fully reset when the modal is opened for a new payment chain, preventing data from leaking across transactions.

## Impact

- **UI/UX**: Cashiers will always see a fresh payment input modal for each transaction.
- **Code**: Minor change to `public/js/pos-staged-payment.js` (adding `resetStageForm()` in the `openModal` function).
