## Why

A product can be created and received in a larger unit such as `BOX`, then discovered to be stocked and sold by its smallest unit such as `PCS` (`1 BOX = 10 PCS`). The conversion did not exist when the purchase was received. This is not an ordinary conversion-unit choice: the product's base accounting UOM itself is wrong. Supplier invoice totals are correct, but stock, purchase unit price, HPP, and last-purchase price are denominated in the wrong unit.

The business needs a safe, auditable base-UOM correction before any stock-affecting outbound activity. The operator supplies only the factual target UOM and factor; the system proposes every derived quantity and purchase-cost result for explicit acknowledgement.

## What Changes

- Replace the existing-base-UOM normalization premise with a privileged, product-level base-UOM correction: `BOX` becomes `PCS`, and the former base becomes a `BOX -> PCS` conversion.
- Add searchable product and target-unit selectors. The target unit is searched from the Unit catalog, not limited to existing product conversion rows.
- Let the operator enter only one positive relationship (`1 old base UOM = factor new base UOM`), reason, and explicit acknowledgement of the proposed result; do not require manual calculated-price entry.
- Rebase all selected purchase and approved receiving quantities, their in-place original `BUY` inventory rows, global stock, and every per-location `product_stocks` row/bucket while preserving each receipt location and all supplier monetary document facts.
- Recalculate purchase-side unit cost, current HPP, and last-purchase price in the new base UOM. Do not change sale prices, tier prices, conversion sale prices, historical sale/POS monetary values, or sale HPP snapshots; require an acknowledgement and prominent reminder to review sales prices before selling.
- Convert/reconcile existing product conversions, conversion barcodes, and price denomination only where a safe mechanical migration is defined; otherwise block with a specific remediation reason.
- Enforce product-wide lineage and execution safety: all old-base purchase/receipt facts in scope must be complete and selected (or void without stock effect), every current stock location must be explainable by that scope, and no serial, completed/dispatched outbound, transfer, return, adjustment, import/opening, or other incompatible stock history may exist. A price-only footprint in another setting is supported by rebasing that setting's purchase-cost indicators; any other-setting physical inventory/history footprint remains a blocker.
- Keep the durable receipt-to-`BUY` provenance, conservative legacy matching, immutable audit trail, dedicated permission, and Purchase-native UI.

## Capabilities

### New Capabilities

- `received-purchase-uom-normalization`: Safely correct a product's base UOM and its received-purchase inventory/cost facts while retaining complete audit evidence.

### Modified Capabilities

- `privileged-received-purchase-corrections`: Distinguish the new base-UOM correction workflow from existing received-purchase monetary corrections.
- `purchase-receiving-notes`: Persist the durable link between an approved receiving detail and its generated inventory transaction.

## Impact

- Affects Purchase receiving and cost replay; Product units, conversions, barcodes, stock, prices, and transactions; and the eligibility interaction with Sales, POS, bundles, transfers, returns, adjustments, and imports.
- Requires a reopened implementation plan, additive audit/schema changes where needed, and project-native searchable UI controls.
- Does not change supplier invoice totals, tax, discounts, payments, due amounts, or any sales price/cost snapshot. Sales pricing remains an explicit follow-up action for the operator.
