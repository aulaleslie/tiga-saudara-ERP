## Context

The POS checkout pipeline (`FinalizePosCheckoutService` → `SplitPosCheckoutPostingAdapter` → `InlinePosCheckoutPostingAdapter`) always posts a fully-paid Cash-on-Delivery `Sale`: it hardcodes `paid_amount = grand_total`, `due_amount = 0`, `payment_status = 'Paid'`, and `payment_term_id = defaultCodTermId()`. Payment is validated to be `>= grand_total` before posting.

The underlying `Modules\Sale\Entities\Sale` already models credit natively (`paid_amount`, `due_amount`, `payment_status`, `payment_term_id`, `due_date`), and the Sales module already collects later payments through `SalePaymentsController::store`, which recomputes `paid_amount`/`due_amount`/`payment_status` (`Unpaid` → `Partial` → `Paid`). Unpaid/partial sales already surface in the "Piutang Belum Tertagih" receivables views. `payment_terms` is seeded with COD and Net 7/14/15/21/30/60/… rows carrying a `longevity` (days).

The supervised cart-action framework (`PosActionApprovalRequest`, `PosApprovalRequestService`, `PosCartActionAuthorizationService`, `PosApprovalTokenService`) already implements request → supervisor approve → short-lived token → consume-at-action, with a Super Admin bypass and a "direct permission holders skip approval" rule.

This change reuses all of the above rather than building new machinery: POS's job is only to **complete an unpaid/partly-paid sale**; collection stays in the Sales document.

## Goals / Non-Goals

**Goals:**
- Let a cashier finish a POS transaction as debt, creating a real `Sale` with an outstanding balance tied to the session.
- Require a named customer and a cashier-selected payment term; compute `due_date = today + term.longevity`.
- Allow an optional down payment (`0 ≤ down_payment < grand_total`) via the existing payment picker; remainder becomes the receivable.
- Gate the debt path with the existing supervisor-approval mechanism and a new `pos.checkout.debt` permission (direct-authorize vs. approval-required vs. Super Admin bypass).
- Keep session cash reconciliation, split-sale allocation, and idempotency correct for a partial/zero payment.

**Non-Goals:**
- No debt collection UI in POS — later payments are made from the existing Sales document (`SalePaymentsController`).
- No new receivables/aging report — existing "Piutang Belum Tertagih" views already cover unpaid/partial sales.
- No schema changes to `sales`/`sale_payments`/`payment_terms` (fields already exist).
- No change to the full-payment checkout path's behavior.

## Decisions

### 1. Reuse the supervised-action framework with a new action type
Add `PosActionApprovalRequest::ACTION_CHECKOUT_AS_DEBT` and map it in `PosApprovalRequestService` and `PosCartActionAuthorizationService` to permission `pos.checkout.debt` and a new `PosSupervisorApproval` action constant. The cashier requests, a supervisor approves (issuing a 10-min token), and the finalize call consumes the token — identical to `QTY_REDUCE`. Direct holders of `pos.checkout.debt` self-authorize; Super Admin bypasses.
- *Alternative considered:* a separate bespoke approval flow for checkout. Rejected — duplicates working infrastructure and diverges the UX the cashiers already know.

### 2. Authorize at finalize, not only at button press
The debt authorization (token consume / direct permission / Super Admin) is enforced inside `FinalizePosCheckoutService.finalize` before posting, mirroring how cart actions consume tokens at action time. The modal drives the request/poll UI, but the server is the gate. This prevents a client bypassing approval by calling finalize directly.

### 3. Un-hardcode the posting adapter instead of forking it
`InlinePosCheckoutPostingAdapter` gains debt-aware inputs from the checkout context:
```
paid_amount    = down_payment
due_amount     = grand_total − down_payment
payment_status = down_payment > 0 ? 'Partial' : 'Unpaid'
payment_term_id= <selected term>
due_date       = today + term.longevity
SalePayment    = created only when down_payment > 0
```
The full-payment path keeps its current values (which are the same formulas with `down_payment = grand_total`), so both paths share one code path.
- *Alternative considered:* a second adapter for debt. Rejected — the sale-shape difference is a few field values, not a different posting algorithm; forking risks drift in dispatch/serial/stock logic.

### 4. Down payment flows through the existing payment picker and allocation
An optional down payment reuses the existing (single or multi) payment representation. Split allocation (`PosCheckoutPaymentSplitService::allocate`) already distributes *any* amount ≤ grand proportionally across split groups by grand-total weight with largest-remainder rounding, so a partial amount allocates correctly and each split `Sale.due_amount` = its grand − its allocated paid. Cash reconciliation already keys `expected_cash_total` off the *actual* cash paid (`cashAmountForSession`), so zero/card/partial-cash down payments feed the session correctly with no change needed there.

### 5. Payment term = all `payment_terms`
The term selector lists all `payment_terms` (matching the regular Sale form's `PaymentTerm::all()`), searchable, mirroring the payment-method search endpoint. `longevity` drives `due_date`. No POS-scoped subset.

### 6. Include debt flag + term in the idempotency hash
`FinalizePosCheckoutService::payloadHash` currently hashes `amount_paid` + method but not term or debt intent. Add `is_debt` and `payment_term_id` to the normalized payload so a debt attempt cannot replay as a full-paid checkout and a term change on retry is not silently replayed with the stale term.

## Risks / Trade-offs

- **Client bypass of approval** → Enforce authorization server-side in `finalize` (Decision 2); never trust a client "approved" flag.
- **Debt sale with no collectible customer** → Hard guard: block debt checkout when `resolved_customer_id` is absent/guest.
- **Down payment ≥ grand total on the debt path** → Validate `0 ≤ down_payment < grand_total`; if the cashier actually pays in full, it is a normal full-payment checkout, not debt.
- **Reconciliation double-counting** → No new cash logic; the debt path emits the same cash events for the actual down-payment amount only. Regression-test that a zero down payment adds nothing to `expected_cash_total`.
- **Split rounding on partial down payment** → Covered by existing largest-remainder allocation; add a test asserting per-split `paid_total` sums to the down payment and each `due_amount` reconciles.
- **Permission misconfiguration** (everyone self-authorizes, or no one can approve) → Seed `pos.checkout.debt` deliberately and document the direct-vs-approval matrix; add authorization tests for cashier-without-permission (approval required), cashier-with-permission (direct), and Super Admin (bypass).

## Migration Plan

1. Add the `pos.checkout.debt` permission and grant it per role policy (supervisor approve capability already implied by `pos.supervisor.approval`).
2. Ship code behind the existing modal — the debt button is additive; the full-payment path is unchanged, so rollback is removing the button/route and the new action mapping. No data migration; unpaid POS sales created before rollback remain valid `Sale` records collectible from the Sales document.

## Open Questions

- Should the debt button be hidden entirely for roles lacking both `pos.checkout.debt` and any supervisor to approve, or shown with an "approval required" affordance? (Lean: show it and let the approval flow explain, consistent with cart actions.)
- Exact role grants for `pos.checkout.debt` (which cashier tiers self-authorize) — to be confirmed with the store's policy during apply.
