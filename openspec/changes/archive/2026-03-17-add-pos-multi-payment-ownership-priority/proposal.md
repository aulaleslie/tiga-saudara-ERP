## Why

POS checkout payment selection currently relies on a single searchable dropdown and a single selected-method state, which causes interaction collisions in the modal and prevents clean multi-method tendering. This now blocks operational needs where one transaction must combine cash and non-cash while honoring split ownership rules and accurate cash-session accounting.

## What Changes

- Replace the single-method checkout interaction with a payment-composer flow where cashier can add, edit, and remove multiple payment rows without UI stacking/collision.
- Allow checkout finalize to accept multiple payment entries (`method`, `amount`, optional `reference`) with deterministic validation and total reconciliation.
- Introduce ownership-priority allocation for payment settlement:
  - Prioritize non-cash tender to terminal POS setting owner share first.
  - Distribute any remaining non-cash and remaining balances proportionally to split ownership groups.
  - Apply cash to residual balances and compute change from aggregate cash tender.
- Persist checkout payment breakdown and group allocation mapping so replay, receipts, reports, and reconciliation can reconstruct exact tender composition.
- Preserve backward compatibility for legacy consumers that still read top-level finalize compatibility fields.

## Capabilities

### New Capabilities
- `pos-checkout-multi-payment-composer`: Cashier composes multiple payment methods in checkout modal with collision-free interaction and explicit remaining-balance guidance.
- `pos-checkout-ownership-priority-payment-allocation`: Checkout payment amounts are allocated across split ownership groups using non-cash-first terminal-owner priority and deterministic proportional fallback.

### Modified Capabilities
- `pos-checkout-split-posting`: Split posting must support multi-payment settlement per group (not single-method-only paid allocation).
- `pos-checkout-split-response-compatibility`: Finalize response must include structured multi-payment details while keeping existing compatibility fields stable.
- `pos-checkout-split-idempotency`: Idempotency/replay guarantees must cover ordered multi-payment payloads and allocation outputs.
- `pos-supervisor-cash-finalization`: Expected-cash and variance flows must use actual cash component from mixed-method checkouts.
- `pos-reports-professional-dashboard`: Payment summaries must aggregate from checkout payment entries, not assume one payment method per checkout.

## Impact

- Affected UI/API: POS sell checkout modal and finalize payload contract.
- Affected services: checkout finalize validation, split posting adapter/payment allocation, idempotency hashing, receipt projection, session expected-cash/reconciliation, reporting summaries.
- Affected persistence: checkout-level payment breakdown storage and per-group payment allocation linkage.
- Affected integrations: existing clients relying on top-level `sale_id`, `sale_payment_id`, `dispatch_ids` remain supported; new clients can consume multi-payment structures.
