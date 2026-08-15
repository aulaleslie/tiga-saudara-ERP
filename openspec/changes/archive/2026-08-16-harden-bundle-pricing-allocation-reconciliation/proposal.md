## Why

Bundle component informational prices are currently editable and copied from the active setting into every replicated bundle, while POS may reload live component prices and apply source-owner tax rules during split posting. These behaviors can make internal allocations diverge from the owning POS bundle snapshot, the customer price, and the intended owner documents, so pricing must be hardened before production bundle use.

## What Changes

- Make bundle component informational prices read-only and server-authoritative, snapshotting each target setting's component sale price during replicated creation with an active-setting fallback.
- Refresh informational-price snapshots only when the relevant setting's bundle copy is saved; preserve other setting copies and stop transaction paths from substituting live component prices.
- Preserve editable parent bundle row prices in Normal Sales and POS while keeping component allocations fixed and assigning the full override difference to the parent amount/residual.
- Keep Normal Sales components non-billable, apply row and prorated global discounts only to commercial parent rows, and retain the single-owner Sales/dispatch model.
- Keep POS discount-free, show bundled components as zero/free to customers, and reconcile internal component allocations from the POS owner's captured bundle snapshot across source-owner Sales documents.
- Change POS bundled split tax treatment so only the POS-owner allocation is tax-included when the POS owner is PKP; all other source-owner bundle allocations remain non-tax.
- Reject POS bundle prices below fixed component allocations and verify quantity, minor-unit, owner-total, receipt, and tax reconciliation with focused regression tests.
- Record component HPP snapshot persistence as a downstream Sequence 9 dependency; this change does not implement HPP snapshots or reporting changes.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `product-bundle-price-configuration`: Replace user-authored component informational prices with per-setting server-derived saved snapshots and target-setting fallback behavior.
- `sale-cart-pricing`: Preserve editable parent pricing while defining parent-only row/global discount treatment and non-billable component behavior.
- `pos-bundle-sale-price-allocation`: Use captured POS-owner snapshots for fixed internal allocations, preserve manual parent price overrides, keep customer component prices zero/free, and remove live-price fallback.
- `pos-checkout-split-posting`: Reconcile owner documents from the captured POS bundle price and apply bundle tax only to the POS-owner allocation.

## Impact

- Product bundle create/edit controller, Livewire item table, bundle Blade forms, and setting-scoped `ProductPrice` resolution.
- Normal Sales cart calculation, discount proration, normalization, and bundle persistence tests.
- POS cart snapshot, price override, split planner, posting adapters, tax allocation, receipt reconstruction, and finalize validation tests.
- Existing bundle definition and transaction data remain intact; no historical rewrite or HPP schema/report change is included.
