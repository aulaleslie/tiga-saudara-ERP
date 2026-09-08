## Why

The POS unit picker shows the base-unit selling price but omits the configured price from conversion-unit cards, forcing cashiers to choose a unit without seeing its relevant price. The conversion price is already available in the business-scoped unit-options response, so the picker should present it clearly without changing cart pricing behavior.

## What Changes

- Show the acting business's configured conversion price on each conversion-unit card in the POS unit picker.
- Clearly distinguish a configured conversion-unit reference price from the final cart charge, which remains governed by existing packing, customer-tier, bundle, tax, rounding, and override rules.
- Show an explicit fallback/unavailable price state when a selectable legacy conversion has no business-scoped conversion price, rather than presenting zero or a misleading configured price.
- Add focused response/rendering verification and a human browser checklist for the unit picker.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-sales-unit-selection`: Require conversion-unit choices to display their business-scoped conversion price or an explicit missing-price state while preserving existing selection and cart-pricing behavior.

## Impact

- Affects the POS unit-options response contract and unit-selection card rendering in `Modules/Pos`.
- Uses existing product conversion price records and existing currency formatting; no schema migration or new dependency is required.
- Does not change cart calculations, conversion eligibility, customer-tier pricing, bundle pricing, checkout persistence, or historical transactions.
