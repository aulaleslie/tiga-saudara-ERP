## Why

POS currently exposes a cart-wide “Ubah Total” action that redistributes one requested grand total across every cart row. That behavior is too broad for cashier correction: one decision silently repriced unrelated rows.

The first revision of this change retired the cart-wide action but also retired every unit-price override, leaving POS with a single row action. That decision is superseded. Normal Sales and Purchase both let an operator correct a row two ways — by unit price (`Ubah Harga Satuan`) or by the row's final total (`Ubah Total Baris`) — and POS must match that established behavior while adding POS supervisory governance. Removing unit-price editing forced cashiers to back-solve a unit price through a total, which is not the workflow the business uses elsewhere.

A defect compounds this: the row-total action was implemented by relabeling the existing unit-price modal in place. The DOM, form state, and JavaScript handlers named `price_override` now drive a row-total operation, so the two operations are indistinguishable in the UI and share mutable client state. The unit-price action is missing entirely.

Separately, the supervised execution path is not failure-safe. The cart is persisted before the approval token is consumed, token consumption performs an unconditional update on a stale model without a lock, and approval source totals are derived as `qty × unit_price` rather than through the canonical totals calculator — so a discounted row's approval delta compares unlike quantities.

## What Changes

- **BREAKING** Retire creation and execution of cart-wide total-price overrides, including the grand-total action, modal, allocation endpoint, active permission surface, and new `TOTAL_PRICE_OVERRIDE` approval requests.
- Restore an authoritative per-row `Ubah Harga Satuan` action that sets one row's unit price, alongside a per-row `Ubah Total Baris` action that sets one row's final total.
- Give each action its own modal ID, form state, endpoint, request object, service method, JavaScript handler, label, and error handling; end the ambiguous reuse of `price_override` DOM state for both operations.
- Introduce two distinct active approval action types, `LINE_UNIT_PRICE_OVERRIDE` and `LINE_TOTAL_OVERRIDE`, both governed by the existing `pos.overrides.price` permission, with action-specific one-time tokens that cannot authorize the other operation.
- Retire the ambiguous `PRICE_OVERRIDE` action for new requests while keeping historical `PRICE_OVERRIDE` and `TOTAL_PRICE_OVERRIDE` records readable and non-authorizing.
- Establish one canonical minor-unit arithmetic authority shared by direct execution, supervised execution, snapshot, approval source/delta, draft, checkout, posting, receipts, and audit, replacing the current split between `PosLineTotalAllocator` and `PosCartTotalsCalculator`.
- Derive approval source values through the canonical totals calculator so unit price is compared against unit price and final row total against final row total.
- Add a `PosCartMutationLock` keyed by setting and POS session that **every** cart writer must acquire — any operation persisting, clearing, replacing, or hydrating the cart, including overrides, quantity changes, line removal, serial assignment, customer changes, discounts, note updates, staged-payment writes, and clear/load — so no competing writer can enter while an override executes or compensates, and no unrelated write is erased by snapshot restoration.
- Add one `PosRowOverrideExecutionCoordinator` serving both direct and supervised paths and both action types: it holds the cart lock across the persistence boundary, locks and revalidates the token row, computes the full mutation before persisting, consumes the token conditionally on `consumed_at IS NULL`, rolls back the database transaction on failure, and restores the exact pre-operation cart before releasing the lock.
- Compare the submitted requested value against the approved value exactly in minor units and reject mismatches without consuming the token, rather than silently substituting the approved value.
- Bind one canonical line fingerprint to action type, requested value, session, line, and requester, covering the real line contract including canonical bundle components.
- Remove the product-ID fallback from approved execution so an approval can only resolve the exact cart `line_id`.

## Capabilities

### New Capabilities
- `pos-line-unit-price-override`: Authoritative POS row unit-price editing, derivation, bundle behavior, invalidation, audit, and checkout reconciliation.
- `pos-line-total-override`: Authoritative POS row-total editing, monetary derivation, bundle behavior, invalidation, audit, and checkout reconciliation.

### Modified Capabilities
- `pos-total-price-override`: Retire supported cart-wide total override behavior while preserving historical records.
- `pos-supervised-cart-actions`: Replace cart-scoped total approval state with two line-scoped override actions, action-specific tokens, exact requested-value comparison, and failure-safe execution ordering.
- `pos-permission-governance`: Govern both active row overrides through `pos.overrides.price` and retire the cart-total permission surface safely.
- `pos-price-override-ui`: Present two visually distinct row actions with separate modals, state, and endpoints, and no cart-wide total control.

## Impact

- POS sell Blade UI, row action rendering, two modals, client approval state keyed by line and action, and approval queue presentation.
- POS cart mutation, snapshot mapping, canonical arithmetic, line fingerprinting, approval authorization/request/token services, an execution coordinator, audit records, and checkout posting.
- Cart-total override routes/controllers/services remain retired and non-mutating; historical database rows and reporting remain readable.
- Central POS permission registry, role bundles, capability response, translations, and permission-matrix tests.
- Focused tests for both row actions, arithmetic in minor units, bundles, packed rows, split-owner posting, approval lifecycle, action-specific tokens, concurrency, failure compensation, cart-total retirement, and historical audit compatibility.
