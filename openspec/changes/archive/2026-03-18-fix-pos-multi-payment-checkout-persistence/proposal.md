## Why

Multi-payment finalization is failing with "Undefined array key 'payment_method_id'" because the code attempts to store a single payment method on `pos_checkouts`, but the multi-payment normalization service returns a structure where individual payment methods are nested inside a `payments[]` array instead of at the root level. This bug blocks all multi-payment checkouts from completing, preventing sales with multiple payment methods (e.g., cash + card) from being finalized and allocated across split ownership groups.

## What Changes

- **Fix FinalizePosCheckoutService**: Extract the first payment method and reference from the normalized multi-payment structure before persisting the checkout ledger, ensuring `resolveCheckoutLedger()` always has the required fields.
- **Align with posting adapter pattern**: Use the same "first payment method as primary" strategy already implemented in `InlinePosCheckoutPostingAdapter`, ensuring consistency across the finalization flow.
- **Enable multi-payment allocation**: Allow checkouts with multiple payment methods to be persisted correctly so subsequent payment allocation across split ownership groups can proceed.

## Capabilities

### New Capabilities

- `pos-multi-payment-checkout-persistence`: Multi-payment checkouts are persisted correctly with the first payment method captured as "primary" on the checkout record, while individual payment details are stored in separate `pos_checkout_payments` records for accurate split group allocation.

### Modified Capabilities

- `pos-multi-stage-payment`: Updated implementation to ensure multi-payment normalized structures include the primary payment method and reference at the root level for ledger persistence compatibility.

## Impact

- **Code**: `Modules/Pos/Services/FinalizePosCheckoutService.php` (normalizeMultiPayment method and resolveCheckoutLedger call site)
- **Database**: Uses existing `pos_checkouts.payment_method_id` and `pos_checkout_payments` tables; no migration needed
- **Tests**: Affected test: `POSCheckoutMultiPaymentFinalizeTest` and related multi-payment integration tests
- **APIs**: No API changes; the finalization endpoint now accepts and correctly processes multi-payment payloads
- **Systems**: POS checkout finalization, payment allocation, split group posting adapter integration
