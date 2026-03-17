## Why

The multi-stage payment flow has a critical bug: when submitting a payment stage, the frontend sends `grand_total = remainder + amount` instead of the original grand total. This causes the backend to calculate remainder incorrectly. Example: Transaction total 60,000, user pays non-cash 40,000 → remainder should be 20,000 but displays as 60,000. Additionally, validation lacks method-specific rules and the payment method input lacks visual feedback.

## What Changes

- **Fix remainder calculation bug**: The frontend sends `grand_total: remainder + amount` instead of the original grand total, causing the backend to initialize with incorrect starting balance on subsequent stages
- **Implement strict validation rules**: Enforce method-specific constraints:
  - Cash payments: amount must be ≥ remainder (allows overpayment for change)
  - Non-cash payments: amount must be ≤ remainder (no overpayment allowed)
- **Add visual feedback**: Apply background styling to payment method input for better selection visibility
- **Improve EDC reference handling**: Validate that non-cash payment methods with `requires_reference=true` have non-empty reference values

## Capabilities

### New Capabilities
- `payment-method-amount-validation`: Rules-based validation for payment amounts based on payment method type (cash vs non-cash)
- `payment-method-visual-feedback`: Visual indicators for payment method selection state and focus

### Modified Capabilities
- `pos-multi-stage-payment`: Fix remainder calculation to track correct running balance across payment stages

## Impact

- **Files**: `public/js/pos-staged-payment.js`, `Modules/Pos/Resources/views/sell.blade.php`
- **Behavior**: Users can now correctly complete multi-stage payments with accurate remainder tracking; validation prevents invalid amount submissions
- **API**: No breaking changes; backend validation already in place
