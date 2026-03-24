## Context

The POS staged payment module (`pos-staged-payment.js`) already enforces payment-type-specific validation on the frontend:
- **Cash**: `amount >= remainder` (must cover the balance)
- **Non-cash**: `amount <= remainder` (cannot overpay)

The backend `PosSellController::stagePayment()` only validates the non-cash case (`amount > remainder → reject`). It does **not** reject cash amounts below the remainder. This is a server-side enforcement gap.

Additionally, there is no visual hint to cashiers about the minimum required amount when a cash method is selected, leading to trial-and-error.

### Files Affected
- `Modules/Pos/Http/Controllers/PosSellController.php` — `stagePayment()` method (~line 533)
- `public/js/pos-staged-payment.js` — `selectPaymentMethod()`, `updateStageValidation()`, hint rendering
- `Modules/Pos/Resources/views/sell.blade.php` — HTML element for the hint label in staged payment modal

## Goals / Non-Goals

**Goals:**
- Add server-side validation in `stagePayment()` to reject cash underpayment (`amount < remainder`)
- Show a minimum amount hint in the staged payment modal when a cash method is selected (e.g., "Minimal: Rp 35.000")
- Add test coverage for the new backend validation

**Non-Goals:**
- Modifying the inline checkout modal (`validatePaymentComposer` in `sell.blade.php`) — only staged payment is in scope
- Changing the finalization flow — payment chain validation at stage time is sufficient
- Enforcing explicit payment ordering — cash naturally becomes last because it must cover the full remainder

## Decisions

### 1. Backend cash underpayment rejection
Add an `if` block directly below the existing non-cash check in `stagePayment()`:

```php
// Existing: non-cash cannot exceed remainder
if (! $paymentMethod->is_cash && $amount > $remainder) { ... }

// New: cash cannot be below remainder
if ($paymentMethod->is_cash && $amount < $remainder) {
    return response()->json([
        'code' => 'CASH_UNDERPAYMENT',
        'message' => "Cash payment must be at least {$remainder}.",
    ], 422);
}
```

**Rationale**: Mirror the existing pattern; use a distinct error code `CASH_UNDERPAYMENT` for clarity.

### 2. UX hint for minimum cash amount
When a cash payment method is selected in `selectPaymentMethod()`, show/update a hint element below the amount input displaying the minimum required amount (the current remainder). When a non-cash method is selected or no method is selected, show "Maksimal: Rp X" or hide the hint.

**Rationale**: This avoids trial-and-error and makes the constraint visible before the cashier types a number.

### 3. Test approach
Add a test case to an existing or new test file that stages a cash payment below the remainder via the backend endpoint and asserts a 422 response with the `CASH_UNDERPAYMENT` code.

## Risks / Trade-offs

- **Edge: remainder with decimals** → The hint formats using `formatPrice()` which already handles IDR formatting. Backend uses `round(..., 2)`. No additional rounding needed.
- **Edge: cash is the only payment** → If remainder equals the grand total and the user selects cash, the minimum is the full amount. This is correct behavior.
