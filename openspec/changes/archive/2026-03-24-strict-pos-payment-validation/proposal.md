## Why

The POS staged payment module enforces payment rules on the frontend (`validateAmountForMethod`) but the backend `stagePayment()` endpoint only validates non-cash overpayment — it does NOT reject cash underpayment. A technically-savvy user could bypass the frontend and submit a cash payment below the remainder via the API. Additionally, when a cash method is selected, there is no visual hint indicating the minimum required amount, which can confuse cashiers.

## What Changes

- **Backend enforcement**: Add server-side validation in `PosSellController::stagePayment()` to reject cash payments where `amount < remainder`
- **UX hint**: When a cash payment method is selected in the staged payment modal, display a hint like "Minimal: Rp 35.000" showing the minimum cash amount required
- **Test coverage**: Add backend test for cash underpayment rejection via the staged payment endpoint

## Capabilities

### New Capabilities

_None_

### Modified Capabilities

- `payment-method-amount-validation`: Adding backend enforcement of cash underpayment rule and a UX hint for minimum cash amount in the staged payment modal

## Impact

- `Modules/Pos/Http/Controllers/PosSellController.php` — `stagePayment()` method
- `public/js/pos-staged-payment.js` — `selectPaymentMethod()` and `updateStageValidation()` functions
- `Modules/Pos/Resources/views/sell.blade.php` — hint label element in staged payment modal HTML
