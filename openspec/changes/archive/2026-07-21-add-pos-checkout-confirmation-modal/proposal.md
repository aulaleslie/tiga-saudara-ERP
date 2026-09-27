## Why

In the current POS checkout flow, cashiers can quickly enter payment amounts and submit them without a final confirmation. This lack of a final visual check can lead to unintended typos resulting in mistaken under-collection (creating receivables) or incorrect change calculation. Adding a native confirmation modal right after entering the amount will serve as a guardrail to ensure cashiers are fully aware of the payment implications before finalizing.

## What Changes

- Add a native Bootstrap confirmation modal to the staged checkout flow.
- Intercept the checkout submission in the staged checkout modal (`pos-staged-payment.js`).
- Display a summary of the payment method, amount entered, and remaining bill in the confirmation modal.
- Implement warning logic in the confirmation modal:
  - If payment equals the remainder: Show a neutral/success message ("Pembayaran Pas").
  - If payment is greater than the remainder: Show an info/warning alert indicating the change amount ("Terdapat Kembalian: Rp X").
  - If payment is less than the remainder: Show a warning alert indicating the payment is short and will leave a balance ("Pembayaran Kurang: Rp Y").
- Require explicit user confirmation ("Lanjutkan") to process the payment.

## Capabilities

### New Capabilities
- `pos-checkout-confirmation`: Provides an explicit confirmation step with warnings for underpayment or overpayment during POS staged checkout.

### Modified Capabilities


## Impact

- **Affected Code**: `public/js/pos-staged-payment.js` (frontend state machine) and `Modules/Pos/Resources/views/sell/modals/staged_checkout.blade.php` (UI elements).
- **User Experience**: Adds an intentional friction point (one extra click) to the checkout flow to significantly reduce costly input errors.
