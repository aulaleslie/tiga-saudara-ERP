## Why

POS debt checkout currently cannot load existing payment terms because its search endpoint references a non-existent model namespace, while the frontend silently renders an empty selector on a failed response. The staged-payment modal's header close control also clears recovery state and then raises a JavaScript error instead of closing, creating inconsistent and potentially destructive behavior compared with the working **Batal** control.

## What Changes

- Make the POS payment-term search endpoint load the shared `payment_terms` records through the repository's canonical PaymentTerm model.
- Make payment-term loading failures visible and actionable in the staged-payment modal instead of leaving an unexplained empty selector.
- Give the modal header close control and **Batal** the same non-destructive dismiss behavior, preserving any in-progress payment chain for recovery.
- Keep payment-chain deletion behind an explicit, clearly labelled reset/cancel action rather than an ordinary modal-dismiss control.
- Prevent every modal-dismiss control from being used while payment processing is active.
- Add regression coverage for payment-term discovery, error handling, modal dismissal, processing locks, and payment-chain preservation.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-debt-checkout`: Clarify that the debt sub-flow must expose existing payment terms and visibly report when terms cannot be loaded.
- `pos-payment-stage-persistence`: Clarify that ordinary modal dismissal preserves an in-progress payment chain and that destructive reset must be explicit.

## Impact

- POS payment-term endpoint in `Modules/Pos/Http/Controllers/PosSellController.php`.
- Staged checkout markup and wiring in `Modules/Pos/Resources/views/sell/modals/staged_checkout.blade.php` and `Modules/Pos/Resources/views/sell.blade.php`.
- Staged-payment client behavior in `public/js/pos-staged-payment.js`.
- Focused POS feature and frontend regression tests; no database schema or external dependency changes.
