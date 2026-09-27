## 1. Permission & approval action wiring

- [x] 1.1 Add `ACTION_CHECKOUT_AS_DEBT` constant to `PosActionApprovalRequest` and a matching supervisor-action constant to `PosSupervisorApproval`
- [x] 1.2 Map the new action to permission `pos.checkout.debt` in `PosApprovalRequestService::requiredPermissionForAction` and `supervisorActionFor`
- [x] 1.3 Map the new action to `pos.checkout.debt` in `PosCartActionAuthorizationService::authorize` (direct permission, token consume, Super Admin bypass)
- [x] 1.4 Register `pos.checkout.debt` in the POS permission registry and seed/grant it per role policy (parity with runtime check)

## 2. Debt-aware finalize service

- [x] 2.1 Extend `FinalizePosCheckoutService::finalize` to accept debt fields (is_debt, payment_term_id, optional down payment, approval token) from the request payload
- [x] 2.2 Enforce server-side authorization for the debt action in finalize (direct permission / consume token / Super Admin) before posting
- [x] 2.3 Guard: block debt checkout when `resolved_customer_id` is absent; require a valid `payment_term_id`
- [x] 2.4 Add a debt variant to `validateCartAndPayment` asserting `0 ≤ down_payment < grand_total` (relaxing the full-payment rule only for the debt path)
- [x] 2.5 Add `is_debt` and `payment_term_id` to `payloadHash` normalization
- [x] 2.6 Thread down-payment amount + debt fields through `resolveCheckoutLedger` / `postCheckout` context to the posting adapter

## 3. Debt-aware posting adapter

- [x] 3.1 In `InlinePosCheckoutPostingAdapter`, compute `paid_amount = down_payment`, `due_amount = grand_total − down_payment`, `payment_status = down_payment>0 ? 'Partial' : 'Unpaid'` (full-payment path keeps current values)
- [x] 3.2 Set `payment_term_id` to the selected term and `due_date = checkout_date + term.longevity`
- [x] 3.3 Create a `SalePayment` only when down payment > 0; skip entirely for zero down payment
- [x] 3.4 Confirm `SplitPosCheckoutPostingAdapter` + `PosCheckoutPaymentSplitService::allocate` distribute a partial down payment across split sales (each split `paid_total` sums to down payment; each `due_amount` reconciles)

## 4. Controller & routes

- [x] 4.1 Extend `PosSellController::checkout` request validation to accept `is_debt` (bool), `payment_term_id` (nullable, exists in `payment_terms`), `approval_token` (nullable string), `payments` (array, optional items).
- [x] 4.2 If `is_debt` is true, map `amount_paid` or `payments` to represent the down payment.
- [x] 4.3 Add a generic query method or route to search available payment terms for the checkout dropdown.

## 5. Checkout UI (sell.blade.php)

- [x] 5.1 Add a "Selesaikan sebagai Utang" action to the payment/checkout modal alongside full payment
- [x] 5.2 Add required payment-term selector (searchable, all terms) with computed due-date display
- [x] 5.3 Add optional down-payment input reusing the existing payment picker, constrained to `0 ≤ down_payment < grand_total`
- [x] 5.4 Enforce customer-required affordance on the debt path (block/prompt when no customer)
- [x] 5.5 Reuse the existing approval request/poll/token UI for the debt action when approval is required

## 6. Tests

- [x] 6.1 Authorization: cashier-without-permission → approval required; cashier-with-permission → direct; Super Admin → bypass
- [x] 6.2 Posting: zero down payment → Unpaid, no SalePayment; partial → Partial with correct paid/due and one SalePayment
- [x] 6.3 Term/due-date: selected term drives `payment_term_id` and `due_date = date + longevity`
- [x] 6.4 Guards: guest customer blocked; missing term blocked; down payment ≥ grand total rejected on debt path
- [x] 6.5 Reconciliation: zero down payment adds nothing to `expected_cash_total`; partial cash down payment recorded correctly
- [x] 6.6 Split allocation: partial down payment allocates proportionally and reconciles per split sale
- [x] 6.7 Idempotency: debt vs. full-payment produce distinct hashes; term change on retry not stale-replayed
- [x] 6.8 Collection: later Sales-document payment recomputes status toward Paid; debt sale appears in receivables view
