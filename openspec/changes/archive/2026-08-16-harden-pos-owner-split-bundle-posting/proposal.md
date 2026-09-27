## Why

POS bundle split posting already creates owner-specific Sales, but its current tax and source-selection behavior does not consistently match the intended ownership model, and the canonical regression suite exposes tax, override, and multi-source failures. Sequence 6 should harden this operational flow so fulfillment ownership is derived deterministically from authoritative locations while the current POS setting remains the customer transaction and tax owner.

## What Changes

- Define the current setting of the active POS session as the POS transaction owner for checkout, receipt, payment, captured bundle pricing, and tax policy.
- Allocate stock-managed non-serial quantities by exact enabled POS sales-location configuration order and available stock; derive each Split Sale owner from the selected location's `setting_id`.
- Treat each selected serial's persisted `location_id` as its authoritative fulfillment source and derive the Split Sale owner from that location.
- Assign non-stock-managed parent and component lines to the first enabled configured POS sales location without a stock-availability or non-PKP-owner search.
- Require owner-aware split posting whenever fulfillment resolves to multiple settings, including when the legacy split-posting feature flag is disabled.
- Preserve fixed bundle component allocations from the POS transaction owner's captured snapshot, place price-override differences entirely in the parent residual, and reconcile all owner Sales exactly in minor units.
- Apply bundle tax only to the allocation posted to the POS transaction owner's Sale when that owner is PKP; keep every foreign fulfillment-owner allocation non-tax.
- Preserve source location, source setting, stock bucket, serial, bundle identity, and group linkage through posting, customer-facing receipt reconstruction, and successful idempotent replay.
- Guarantee rollback of every group when any owner group fails, and add focused regression coverage for the Sequence 6 edge combinations.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-checkout-split-posting`: Define POS transaction ownership, exact location-order fulfillment, location-derived Split Sale ownership, first-location non-stock ownership, bundle tax routing, multi-owner adapter selection, group isolation, and atomic posting behavior.
- `pos-checkout-serial-stock-validation`: Replace owner-tax-priority allocation for non-serial stock with exact configured-location order and make selected serial location the authoritative source and owner boundary.
- `pos-checkout-split-idempotency`: Extend split replay guarantees to the complete persisted owner map and define failure rollback and retry behavior.

## Impact

- Affected POS services include stock allocation, non-stock source resolution, split planning, owner-aware adapter selection, inline/split posting, finalize orchestration, receipt reconstruction, and checkout-to-Sale persistence.
- Existing `pos_checkout_sales`, Sales, Sale details, bundle items, dispatches, stock transactions, serial records, and payment mappings remain the persistence model; no schema change is expected.
- Existing POS bundle, split-posting, serial, stock-bucket, receipt, payment, and idempotency tests will be updated or extended.
- Normal Sales ownership and dispatch behavior, bundle authoring/lifecycle, HPP, returns, and report redesign are outside this change.
