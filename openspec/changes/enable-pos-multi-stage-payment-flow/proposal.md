## Why

The multi-stage sequential payment flow was designed and fully implemented, but never wired into the user-facing checkout button. The "Pilih Pembayaran" button still opens the old modal that requires all payment methods to be selected upfront. Meanwhile, the staged payment infrastructure (frontend state machine, backend API endpoints, session chain tracking, reload recovery logic) exists but is dormant. This blocks users from the designed workflow: pay incrementally with different methods in sequence (e.g., BRI → BNI → CASH), with each stage committed immediately and the remainder recalculated.

## What Changes

- **Wire staged payment to checkout button**: Replace the old `openPaymentModal()` call with `PosStagedPayment.openModal()` to activate the sequential payment flow
- **Fix payment chain session key**: Replace `sale_id`-based keys with cart-scoped `cart_token` (UUID), since Sales don't exist until finalize
- **Add cart token to cart snapshot**: Return `staged_payment_token` from `cartShow` endpoint so the token survives page reloads
- **Fix field name mismatch in payment chain**: Map session chain entries from `method_id`/`amount` to `payment_method_id`/`amount_paid` before finalize normalization
- **Wire finalize to gratitude modal**: The gratitude modal "Lanjut Jualan" button now calls finalize, completes the payment flow
- **Add reload recovery hook**: On page load, check if a payment chain is in progress and auto-open the staged modal at the correct stage
- **Add back-to-cart option**: Close button on staged modal clears the payment session and returns to cart (only available before first payment)

## Capabilities

### New Capabilities
- `pos-staged-checkout-wiring`: Ability to activate the multi-stage sequential payment flow from the POS checkout button, replacing the single-batch modal
- `pos-payment-chain-session-scope`: Ability to track payment chains keyed by cart-scoped token (instead of sale_id), persisted in session, survives reload
- `pos-checkout-finalize-integration`: Ability to trigger finalize from the gratitude modal after all payment stages are complete

### Modified Capabilities
- `pos-multi-stage-payment-flow`: Session chain now uses `cart_token` instead of `sale_id`; field names in payment entries must be normalized (`payment_method_id`/`amount_paid`)

## Impact

- **Frontend JavaScript**: `pos-staged-payment.js` refactored to accept `cart_token` + `grandTotal` instead of fetching Sale via API; reload recovery checks `cart_token` from snapshot
- **Frontend Routes**: Blade view (`sell.blade.php`) button handler redirected to staged flow; gratitude modal button wired to finalize
- **Backend API**: `PosCheckoutController` (stagePayment, getPaymentChain) uses `cart_token` instead of `sale_id`; no more Sale lookup on stage entry
- **Backend Routes**: API routes updated to validate `cart_token`; new DELETE endpoint for clearing payment chain
- **Cart Service**: `buildSnapshot()` returns `staged_payment_token`; new field in cart session
- **Checkout Service**: `checkoutFinalize()` reads `cart_token`, performs field name mapping before calling finalize
- **Database**: No schema changes (SalePayment already has `stage_order`, `edc_reference`, `idempotency_key`)
- **User Experience**: Single, consistent checkout flow; reload recovery transparent; ability to abandon incomplete payment (back to cart)
