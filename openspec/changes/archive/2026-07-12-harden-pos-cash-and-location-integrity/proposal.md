## Why

POS cash settlement currently understates expected drawer cash whenever a cashier gives change because checkout finalization records the transaction total as cash inflow and then deducts change again. Newly created locations can also remain unavailable to POS because their sale-location assignments are inserted without invalidating the affected settings' cached location lists, while the current onboarding behavior enables them across every business.

## What Changes

- Record the actual cash tendered during checkout as the session cash-in amount and record customer change exactly once as a separate cash-out event, including cash-only and mixed-payment checkouts.
- Keep expected cash derived from the session cash-event ledger and align session summaries with the same tender-minus-change semantics.
- Preserve closed-session ledger and audit history; do not silently rewrite historical closed sessions.
- Add focused detection and regression coverage for affected cash-overpayment scenarios, including the Rp5,000,000 opening cash, Rp780,000 sale, Rp800,000 tender, and Rp20,000 change case.
- Automatically enable a newly created location as a sale/POS location for its owning business only.
- Invalidate the sale-location resolver cache for every setting whose assignment changes so the new location is available to POS immediately.
- Require other businesses to explicitly enable a location before it becomes available to their POS flows.

## Capabilities

### New Capabilities

- `pos-sale-location-onboarding`: Defines owning-business assignment, cross-business opt-in, and immediate cache-consistent POS availability for newly created locations.

### Modified Capabilities

- `pos-session-cash-reconciliation`: Corrects cash-event semantics so actual tender is recorded as inflow and change is deducted exactly once when calculating expected drawer cash.

## Impact

- POS checkout finalization, cash-event persistence, expected-cash cache updates, session summary/reconciliation behavior, and focused checkout/session tests.
- Location model lifecycle hooks, quick-add and standard location creation flows, `setting_sale_locations` defaults, sale-location cache invalidation, and Setting/POS feature tests.
- No barcode behavior, product lookup, terminal-to-location selector, external API, or new dependency changes.
- Existing closed-session cash events remain immutable; any historical analysis is diagnostic rather than an automatic data rewrite.
