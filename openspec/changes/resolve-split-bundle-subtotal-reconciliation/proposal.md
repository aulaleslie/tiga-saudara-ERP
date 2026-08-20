## Why

POS split checkout correctly allocates a bundle's captured price across owner-specific Sales, but final posting currently hard-codes fulfilled `sale_bundle_items.price` and `sub_total` to zero. This discards the immutable internal component revenue allocation needed for owner audit and whole-bundle reversal even though the owner Sale totals reconcile, and an existing regression test also applies tax to the full customer bundle instead of only the PKP POS-owner allocation.

## What Changes

- Persist each fulfilled POS bundle component's captured internal allocation in `sale_bundle_items.price` and `sale_bundle_items.sub_total`, including quantity scaling and minor-unit-safe allocation.
- Keep owner-group `sale_details.sub_total` and Sale headers authoritative for revenue; component values are nested allocation identity and MUST NOT be added again by reports or totals.
- Preserve the customer-facing presentation: the receipt and transaction detail show the complete captured bundle price on the parent and show components as zero/free.
- Reconcile tax only against the bundled allocation posted to the PKP POS transaction owner; other source-owner component Sales remain non-tax.
- Preserve the whole-bundle return contract: users return a parent bundle quantity, never an individual component; internal reversal uses the original persisted parent/component allocations and restores the complete composition proportionally.
- Add focused regression coverage for allocation persistence, multiple quantities and rounding, price overrides, tax, receipt presentation, whole-bundle returns, reporting non-duplication, atomic failure, and idempotent replay.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-bundle-sale-price-allocation`: Make captured internal component allocations immutable persisted transaction values while retaining zero/free customer presentation and whole-bundle commercial identity.
- `pos-checkout-split-posting`: Require owner-group posting to persist parent residual and component allocation breakdowns without revenue duplication, and reconcile tax to the POS-owner allocation.

## Impact

- Affects POS split planning/posting and `SaleBundleItem` persistence, principally `PosCheckoutSplitPlannerService` and `InlinePosCheckoutPostingAdapter`.
- May affect POS bundle return value resolution, receipt reconstruction, and reports that read both `sale_details` and `sale_bundle_items`; these consumers require focused regression verification rather than broad redesign.
- Does not change database schema, customer bundle pricing, normal Sales' zero-commercial component rows, HPP calculation, inventory ownership, or the rule blocking component-only returns.
