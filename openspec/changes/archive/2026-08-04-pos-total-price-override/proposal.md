## Why

Cashiers can currently override a single cart line price, but cannot quote an agreed total for an entire POS basket. Directly replacing the derived grand total would break payment, receipt, tax, and split-owner reconciliation; the POS needs a controlled total override that resolves into auditable cart-line amounts.

## What Changes

- Add a POS cart-total override that accepts a requested total greater than, less than, or equal to zero.
- Add a distinct direct permission and supervised approval action for total-price overrides, reusing the existing request, supervisor decision, one-time token, and cashier confirmation flow.
- Allocate an approved target total proportionally and deterministically across existing cart rows without splitting rows in the cashier UI.
- Make each adjusted row's exact allocated line total authoritative while showing an effective unit price, so adjusted row totals reconcile exactly to the approved cart total despite per-unit rounding.
- Persist override/audit context and invalidate a pending or approved-but-unconsumed total override when its cart changes.
- Preserve normal checkout, receipt, payment validation, tax display, packed pricing, bundles, and split-owner sale posting using the adjusted line totals.

## Capabilities

### New Capabilities

- `pos-total-price-override`: Supervised POS cart-total overrides, exact line-total allocation, cart UI state, and audit behavior.

### Modified Capabilities

- `pos-supervised-cart-actions`: Extend the existing request/approve/token/confirm governance to cart-total overrides and cart-change invalidation.
- `pos-permission-governance`: Add the POS total-price-override permission to the supported permission model and role bundles.

## Impact

- Affects `Modules/Pos` cart services, totals calculation/snapshot state, approval request/token services, controller routes/requests, permission configuration/matrix, the supervisor queue, sell-page JavaScript and modal partials.
- Affects POS checkout snapshots and downstream receipt, payment, tax, packed-line, bundle, and split-owner posting paths by providing exact adjusted line totals.
- Requires focused feature and UI-flow tests; no external service or destructive data migration is expected.
