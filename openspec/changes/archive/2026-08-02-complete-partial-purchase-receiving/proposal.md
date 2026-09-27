## Why

When a supplier cannot fulfil every product or quantity on a purchase order, approved receipts leave the purchase in `RECEIVED PARTIALLY`. The global supplier-payment workflow accepts only fully `RECEIVED` purchases, so users cannot close and pay for the goods actually delivered without unsafe manual data changes.

## What Changes

- Add a controlled purchase-level action to complete a partially received purchase as a supplier shortfall.
- Add a dedicated `purchases.receive.complete_shortfall` permission; receiving and receiving-approval permissions alone do not grant this authority.
- Require a shortfall reason and present a final before/after line and financial preview before completion.
- Normalize the purchase to approved receipt totals: retain received lines with their quantity set to the cumulative approved received quantity, and remove never-received lines.
- Recalculate purchase monetary totals and payment summaries atomically, mark the purchase `RECEIVED`, and prevent any further receiving for that purchase.
- Persist an immutable audit record of the actor, reason, original and final lines, receipt totals, and financial before/after values.
- Surface the same action from the normal purchase list, purchase detail, and purchase receiving history while using one shared eligibility rule and confirmation flow.

## Capabilities

### New Capabilities

- `partial-purchase-receiving-completion`: Authorize, preview, audit, and complete supplier-shortfall purchases using approved receiving totals.

### Modified Capabilities

- `global-purchase-multi-payment`: A successfully completed shortfall purchase becomes exactly `RECEIVED` and is eligible for the existing global payment workflow using its normalized balance.
- `purchase-permission-normalization`: Define, seed, display, and consistently enforce the dedicated purchase shortfall-completion permission.

## Impact

- Affected code: `Modules/Purchase` purchase entities, migrations, receiving controller/service, normalizer integration, routes, list/detail/receiving Blade actions, and feature tests; centralized permission configuration and seeding.
- Data: a new immutable completion audit aggregate; in-place updates to retained purchase detail quantities; deletion only of never-received detail rows; recomputed purchase monetary summary fields.
- Integrations: approved received-note details remain the receipt and stock source of truth; the existing global purchase-payment eligibility query can admit the normalized purchase once its status is `RECEIVED`.
