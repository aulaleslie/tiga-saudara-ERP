# Proposal

## Why

Cashiers need to collect outstanding Kas Bon directly from POS while accounting for money received in their current session. Existing global POS collections settle Sales but do not provide the cashier checkout experience or session cash attribution.

## What Changes

- Add a permission-controlled Bayar Kas Bon button and searchable outstanding-debt modal scoped to the active business across original cashiers, terminals, and sessions.
- Review one transaction's current items, prices, totals, prior payments, and live due before collecting a partial or full payment.
- Offer checkout-style cash, non-cash, and mixed payments without a Kas Bon option.
- Atomically settle generated Sales and attribute collection to the receiving active session, including valid sessions without terminals.
- Include physical cash in pickup, expected cash, and reconciliation; report non-cash collections separately.
- Print a collection receipt preserving the original checkout history.
- Leave Sales payment invalidation and cash correction workflows outside this change; invalidation does not erase money physically received.
- Use focused verification only; no full-suite test requirement.

## Capabilities

### New Capabilities

- `pos-kas-bon-collection`: Cashier-facing discovery, current-bill review, single-transaction collection, receiving-session attribution, and collection receipts.

### Modified Capabilities

- `pos-debt-checkout`: Recognize the authorized active-session POS collection entry point alongside existing collection workflows.
- `pos-session-cash-reconciliation`: Report Kas Bon collections separately and include their physical cash movement in session balances without counting non-cash as drawer cash.

## Impact

Primary changes are in Modules/Pos routes, controllers, services, entities, migrations, sell Blade/JavaScript, session summaries, reconciliation, and receipt presentation. Reuse canonical Sale settlement, generated-Sale resolution, ownership priority, current receipt monetary projection, and session cash events. Add a dedicated permission through existing permission registration and role-bundle conventions. Existing global payments retain their authorization and session-independent behavior. No historical backfill or new external dependency is required.
