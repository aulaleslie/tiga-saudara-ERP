## Why

POS can already sell non-stock-managed products without inventory side effects, but its generated Sale ownership is inconsistent: standalone non-stock parents use the terminal setting while stockless bundle components use a non-PKP-only source rule. POS also omits an auditable DispatchDetail for non-stock lines even though every POS transaction is immediately completed and dispatched.

## What Changes

- Resolve every non-stock-managed POS parent or bundle component to the first enabled POS sales-location configuration entry, ordered by `setting_sale_locations.position`; do not use the cashier's current business or a first-non-PKP filter.
- Preserve existing POS allocation priority for stock-managed parents and bundle components: stock availability determines their source owner/location and inventory effects.
- Create an approved audit-only DispatchDetail for every non-stock-managed POS line or component included in a generated owner Sale, using its resolved POS source location.
- Keep every POS-generated Sale immediately `DISPATCHED` after successful checkout, with no new service or work-order lifecycle.
- Ensure non-stock DispatchDetails never require stock, location-stock, serial, product-stock, or inventory-transaction work.
- Preserve split posting: a mixed-owner POS cart or service bundle with a stock-managed component may create one Sale per existing owner split group, while payment, receipt, return, and audit mappings reconcile to the one checkout.

## Capabilities

### New Capabilities

- `pos-nonstock-dispatch-audit`: POS checkout records immediate, approved, audit-only dispatch evidence for non-stock-managed sales content without inventory effects.

### Modified Capabilities

- `pos-checkout-split-posting`: Non-stock ownership and stockless bundle-component ownership use the first ordered configured POS sales-location source; split groups retain existing stock allocation ownership rules.
- `pos-bundle-selection-checkout`: Bundled non-stock and stock-managed products retain their separate ownership and dispatch/inventory responsibilities through POS checkout.

## Impact

Affected code includes POS split planning, inline/split checkout posting, DispatchDetail persistence, checkout split mappings, receipts, POS Return snapshots, and POS checkout feature coverage. It reuses existing `setting_sale_locations`, Sales, Dispatch, payment-allocation, bundle, and return data structures; no separate service/work-order flow is introduced.
