## Why

Cashiers currently cannot complete a POS sale unless it is fully paid at checkout. Trusted customers who buy on credit ("utang") must be handled outside the POS, breaking the counter flow and leaving no receivable record tied to the session. We need to let the cashier finish a transaction as debt — creating a real Sale with an outstanding balance — while keeping the same supervisor-approval safeguards that already govern high-risk cart actions (quantity reduction, line removal).

## What Changes

- Add a **"Selesaikan sebagai Utang" (finish as debt)** path to the POS payment/checkout modal, alongside the existing full-payment path.
- The debt path requires a **named customer** (walk-in/guest is blocked) and a **required payment term** selection (any existing `payment_terms` row); `due_date` is computed as `today + term.longevity`.
- The debt path allows an **optional down payment** at checkout (`0 ≤ down_payment < grand_total`) using the existing payment picker; the remainder becomes the sale's outstanding balance. All later collection happens from the existing Sales document (`SalePaymentsController`), not from POS.
- Gate the debt path with the **same approval mechanism as cart actions**: cashiers holding a new `pos.checkout.debt` permission self-authorize; everyone else raises a supervisor approval request and consumes a short-lived token at finalize. Super Admin bypasses, consistent with existing cart-action behavior.
- Make the checkout posting **debt-aware**: instead of hardcoding a fully-paid COD sale, persist `paid_amount = down_payment`, `due_amount = grand_total − down_payment`, `payment_status = Partial|Unpaid`, the selected `payment_term_id`, and computed `due_date`; create a `SalePayment` only when a down payment is taken.
- Ensure session cash reconciliation and split-sale allocation reflect the **actual** down-payment amount, and include the debt flag + payment term in the checkout idempotency hash.

## Capabilities

### New Capabilities
- `pos-debt-checkout`: Completing a POS transaction as customer debt — required customer and payment term, optional down payment, supervisor-approval gating, and posting a Sale with an outstanding balance for later collection from the Sales document.

### Modified Capabilities
- `pos-supervised-cart-actions`: Add a new supervised action type for finishing a transaction as debt, following the existing request → approve → token-consume flow.
- `pos-permission-governance`: Introduce the `pos.checkout.debt` permission and its assignment for direct-authorize vs. approval-required behavior.
- `pos-checkout-finalize-integration`: The finalize endpoint accepts debt fields (payment term, optional down payment, approval token) and posts a Sale with an outstanding balance instead of always fully paid.

## Impact

- **Entities**: `Modules/Pos/Entities/PosActionApprovalRequest` (new action constant); reuses `Modules/Sale/Entities/Sale`, `SalePayment` (no schema change — fields already exist).
- **Services**: `PosApprovalRequestService`, `PosCartActionAuthorizationService` (permission + supervisor-action mapping); `FinalizePosCheckoutService` (debt-aware validation, idempotency hash, carry debt fields); `Adapters/InlinePosCheckoutPostingAdapter` (un-hardcode paid/due/status/term/due_date, conditional SalePayment).
- **HTTP/Routes**: `PosSellController` finalize/preflight (accept + guard debt fields); optional payment-terms lookup endpoint mirroring payment-method search.
- **UI**: `Modules/Pos/Resources/views/sell.blade.php` (debt button, down-payment input, term selector, reuse of existing approval token polling UI).
- **Permissions/roles**: seed `pos.checkout.debt` and grant to supervisor/cashier roles as configured.
- **Reporting/collection**: debt sales surface automatically in existing "Piutang Belum Tertagih" receivables views; collection handled by existing `SalePaymentsController`.
