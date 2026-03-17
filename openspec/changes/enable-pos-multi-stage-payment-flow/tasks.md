# Implementation Tasks: Enable POS Multi-Stage Payment Flow

## 1. Backend: Cart Token Generation & Return

- [x] 1.1 Add `staged_payment_token` field to `PosCartSessionStore` cart array structure
- [x] 1.2 Modify `PosCartService->buildSnapshot()` to return `staged_payment_token` in cart snapshot
- [x] 1.3 Ensure `cartShow` endpoint returns the token (already returns snapshot, just verify)
- [x] 1.4 Test: Fresh cart returns new token, reload returns same token

## 2. Backend: Update stagePayment to Use Cart Token

- [x] 2.1 Modify `/api/pos/sell/checkout/stage-payment` validation rules: accept `cart_token` instead of `sale_id`
- [x] 2.2 Remove `Sale::findOrFail()` lookup from `PosCheckoutController->stagePayment()`
- [x] 2.3 Accept `grand_total` as a parameter (passed from frontend) instead of fetching from Sale
- [x] 2.4 Update session key from `payment_chain_{sale_id}` to `payment_chain_{cart_token}`
- [x] 2.5 Ensure session chain initialization uses passed `grand_total` instead of `sale->due_amount`
- [x] 2.6 Test: stagePayment accepts cart_token, stores SalePayment, updates session chain correctly

## 3. Backend: Update getPaymentChain to Use Cart Token

- [x] 3.1 Modify `/api/pos/sell/checkout/payment-chain` validation to accept `cart_token` instead of `sale_id`
- [x] 3.2 Remove `Sale::findOrFail()` lookup from `PosCheckoutController->getPaymentChain()`
- [x] 3.3 Update session key to `payment_chain_{cart_token}`
- [x] 3.4 Test: getPaymentChain with token returns correct chain or `has_chain: false`

## 4. Backend: Add Delete Payment Chain Endpoint

- [x] 4.1 Add `DELETE /api/pos/sell/checkout/payment-chain` route accepting `cart_token`
- [x] 4.2 Implement `PosCheckoutController->resetPaymentChain()` method
- [x] 4.3 Clear `payment_chain_{cart_token}` from session
- [x] 4.4 Test: DELETE with cart_token removes session key

## 5. Backend: Fix checkoutFinalize Field Mapping

- [x] 5.1 Modify `PosSellController->checkoutFinalize()` to read `cart_token` instead of `sale_id`
- [x] 5.2 Update session key to `payment_chain_{cart_token}`
- [x] 5.3 Add field name mapping: convert session entries from `method_id`/`amount` to `payment_method_id`/`amount_paid`
- [x] 5.4 Test: finalize with cart_token maps fields correctly and creates Sale with all payments

## 6. Frontend JS: Update Staged Payment Module

- [x] 6.1 Modify `openModal(cartToken, grandTotal)` signature to accept token and grandTotal (not saleId)
- [x] 6.2 Remove `initializeNewPaymentChain(saleId)` fetch to `/api/sales/{saleId}` endpoint
- [x] 6.3 Initialize chain directly with `remainder = grandTotal` passed in parameter
- [x] 6.4 Update `checkReloadRecovery()` to call `GET /api/pos/sell/checkout/payment-chain?cart_token=XXX`
- [x] 6.5 Update `submitStagePayment()` to send `cart_token` instead of `sale_id` to stage-payment endpoint
- [x] 6.6 Test: openModal initializes correctly without fetching Sale

## 7. Frontend JS: Add onComplete Callback

- [x] 7.1 Modify `handlePaymentComplete(changeAmount)` to invoke a configurable `onComplete` callback instead of just showing gratitude modal
- [x] 7.2 Add public API to set callback: `PosStagedPayment.setOnComplete(callback)`
- [x] 7.3 Callback receives `(changeAmount)` and should trigger finalize
- [x] 7.4 Test: onComplete is called when remainder = 0

## 8. Frontend JS: Update Modal UI Logic

- [x] 8.1 Show close button only when `paymentChain.payments.length === 0` (empty chain)
- [x] 8.2 Hide close button after first payment is committed
- [x] 8.3 Close button calls `DELETE /api/pos/sell/checkout/payment-chain?cart_token=XXX` before closing
- [x] 8.4 Test: close button available on empty chain, hidden after payment

## 9. Frontend Blade: Wire Checkout Button to Staged Flow

- [x] 9.1 Modify button click handler to generate/retrieve `cartToken` from `currentSnapshot.staged_payment_token`
- [x] 9.2 Replace `openPaymentModal()` call with `PosStagedPayment.openModal(cartToken, grandTotal)`
- [x] 9.3 Ensure `currentSnapshot` is available in button handler scope
- [x] 9.4 Test: Button click opens staged modal with correct token and grand total

## 10. Frontend Blade: Add onComplete Callback

- [x] 10.1 After `PosStagedPayment.initialize()`, call `PosStagedPayment.setOnComplete(callback)`
- [x] 10.2 Callback should POST to `/pos/sell/checkout/finalize` with `{ cart_token, idempotency_key }`
- [x] 10.3 On success (201): close staged modal, open receipt in new tab, clear cart, reset POS
- [x] 10.4 On error: display error in a temporary alert
- [x] 10.5 Test: Clicking "Lanjut Jualan" calls finalize and completes transaction

## 11. Frontend Blade: Wire Gratitude Modal Button

- [x] 11.1 Modify `#pos-gratitude-modal` "Lanjut Jualan" button to call the onComplete callback (finalize)
- [x] 11.2 Ensure button is part of the modal's OK action
- [x] 11.3 Test: Button click triggers finalize

## 12. Frontend Blade: Add Reload Recovery

- [x] 12.1 On DOMContentLoaded, check if `currentSnapshot.staged_payment_token` exists
- [x] 12.2 If token exists, call `GET /api/pos/sell/checkout/payment-chain?cart_token=XXX`
- [x] 12.3 If `has_chain: true`, call `PosStagedPayment.openModal(token, grandTotal)` with recovered state
- [x] 12.4 If `has_chain: false`, clear token from snapshot (optional cleanup)
- [x] 12.5 Test: Reload during payment opens modal with recovered chain

## 13. Integration Testing

- [x] 13.1 Test fresh cart → single CASH payment → finalize → receipt → cart clear
- [x] 13.2 Test multi-stage: BRI 500k + BNI 500k + CASH 500k = 1.5M total
- [x] 13.3 Test overpayment: total 1M, user pays 1.2M, verify change = 200k displayed
- [x] 13.4 Test reload mid-chain: pay 1 stage, refresh, verify modal reopens with chain
- [x] 13.5 Test back to cart: no payments → close button → returns to normal cart
- [x] 13.6 Test no close after payment: 1 payment committed → close button disabled
- [x] 13.7 Test idempotency: duplicate submission returns same response
- [x] 13.8 Test EDC validation: non-cash without reference → error; with valid reference → accepted

## 14. Unit Tests

- [x] 14.1 Test `stagePayment` with cart_token, field mapping, remainder calculation
- [x] 14.2 Test `getPaymentChain` with cart_token, returns correct structure
- [x] 14.3 Test `resetPaymentChain` clears session key
- [x] 14.4 Test `checkoutFinalize` reads cart_token, maps fields, calls finalize service
- [x] 14.5 Test cart snapshot includes and preserves staged_payment_token
- [x] 14.6 Run existing tests: `php artisan test --filter=POSCheckoutMultiPayment POSCheckoutFinalize`

## 15. Documentation & Cleanup

- [x] 15.1 Update API documentation for `/pos/sell/checkout/stage-payment` (cart_token instead of sale_id)
- [x] 15.2 Update API documentation for `/pos/sell/checkout/payment-chain` (cart_token parameter)
- [x] 15.3 Add documentation for `/pos/sell/checkout/payment-chain` DELETE endpoint
- [x] 15.4 Remove or comment out old payment modal wiring (keep HTML for now)
- [x] 15.5 Update inline code comments for cart token session pattern
- [x] 15.6 Verify all tests pass and no regressions
