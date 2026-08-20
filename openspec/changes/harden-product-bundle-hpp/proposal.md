## Why

Bundle fulfillment moves parent and component inventory, but current Sale HPP persistence and operational reports cost only `sale_details` parent rows. This can omit stock-component cost and, in POS component-only split groups, can recognize a parent cost that the group did not physically fulfill, producing materially incorrect profitability and inventory movements.

## What Changes

- Persist immutable unit, total, source-setting, PKP-classification, source-label, and timestamp HPP snapshots for each fulfilled bundle component.
- Recognize zero HPP for non-stock parents and components while distinguishing verified non-stock zero from missing stock cost and from a parent not fulfilled by a POS owner group.
- Resolve live stock-item HPP from the physical stock owner's positive average purchase price, then deterministically fall back to nearby same-PKP settings before opposite-PKP settings; do not derive HPP from bundle revenue allocation or last purchase price.
- Prevent POS component-only owner groups from recognizing bundle-parent HPP, and ensure parent and component physical cost are each recognized exactly once.
- Aggregate parent and component HPP consistently in profit/loss and operational movement consumers without double-counting bundle revenue.
- Persist return HPP reversal identity from the original immutable parent/component snapshot and apply it only when the physical return becomes effective, including partial bundle cash returns and replacement dispatches.
- Extend focused creation, split-posting, reporting, return, fallback, retry, and backfill regression coverage.

## Capabilities

### New Capabilities
- `product-bundle-hpp-accounting`: Defines physical-fulfillment cost persistence, PKP-aware average-cost fallback, exact-once report aggregation, missing-cost evidence, and bundle HPP invariants.

### Modified Capabilities
- `sales-cost-snapshots`: Extends immutable Sale cost snapshots to bundle components and defines the owner-aware average-cost fallback and backfill behavior.
- `pos-checkout-split-posting`: Prevents component-only split groups from recognizing parent HPP and snapshots each group's actual parent/component fulfillment.
- `pos-return-approval-execution`: Adds immutable HPP reversal and replacement-dispatch HPP behavior to bundle return execution.
- `operational-general-ledger-report`: Includes bundle component HPP and effective return reversals in operational cost and inventory movements exactly once.

## Impact

- Schema and models: `sale_bundle_items` and the execution-aligned Sales Return detail/context require immutable HPP and source metadata.
- Sale/POS posting: `SalesCostSnapshotService`, Normal Sales persistence, POS split planning/posting, component persistence, and missing-cost warnings.
- Returns: POS Return approval planning/execution and Sales Return linkage for proportional parent/component cost reversal and replacement dispatch cost.
- Reports: shared Sale HPP aggregation used by profit/loss, operational movement/general ledger, trial balance, and balance-sheet earnings.
- Historical tooling: focused bundle-aware backfill/replay support must preserve authoritative imported Sale-detail HPP and avoid parent/component double-counting.
