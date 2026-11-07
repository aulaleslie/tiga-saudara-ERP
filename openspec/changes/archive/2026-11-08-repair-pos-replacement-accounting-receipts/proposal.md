# Proposal

## Why

POS transaction 10717 prints Rp4,825,000 instead of Rp2,600,000 after a cross-owner printer replacement. The source sale retains the replaced value in its header and payments while a generated replacement sale adds the same value again; receipt allocation consequently charges Rp2,270,000 for Rp45,000 paper.

## What Changes

- Conserve customer charges, effective payments, and debt when replacement value moves between owners, including partial and repeated replacements.
- Preserve original customer payment evidence through auditable owner allocation adjustments rather than treating generated replacement payments as new collections.
- Reconcile source and replacement sale headers, payment allocations, settlement, and receipts atomically; preserve current serial lineage and legitimate later price edits.
- Provide business-scoped, read-only candidate discovery and explicit transaction repair previews. Require reviewed evidence, actor, reason, and explicit apply for historical mutation; block ambiguous cases.
- Add synthetic transaction-10717 regression coverage and lifecycle, receipt, settlement, payment, and repair safeguards.

## Capabilities

### New Capabilities

- `pos-replacement-accounting-repair`: Evidence-based discovery, preview, and audited repair of historical replacement accounting duplication.

### Modified Capabilities

- `pos-return-cross-owner-replacement`: Conserve commercial and settlement value across source and replacement owners while preserving payment provenance.
- `pos-receipt`: Explicitly reconcile replacement receipts without reallocating duplicated replacement value to unrelated products.

## Impact

Primary integration points are `PosReturnLifecycleService`, `PosReachableSalesResolver`, `PosSettlementProjectionService`, `PosReceiptReprintProjectionService`, Sale payment evidence, and reporting consumers of effective payments. Add a dedicated repair command/service and additive audit/allocation persistence where existing records cannot represent the transfer safely. Reuse Laravel module conventions and existing payment invalidation/allocation mechanisms where compatible; preserve original payment facts and checkout snapshots.

The existing `pos:repair-return-balances` cash-only contract remains unchanged. No production data repair is authorized by creating or implementing this proposal: first produce a dry-run report for review. This proposal does not redesign Sales Return custody, stock, HPP, tax policy, receipt layout, or historical receipt behavior.
