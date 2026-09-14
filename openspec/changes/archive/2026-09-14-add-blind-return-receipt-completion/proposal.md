## Why

Approved partial return batches currently remain indefinitely in transit: the original location cannot blindly recount them, restore inventory, close serialized custody, fulfill their reserved obligations, or complete the transfer. Delivery 9 closes that loop while preserving independent approval for every physical return batch.

## What Changes

- Add a completely blind, scanner-first return-receipt workflow that starts empty and exposes no expected product, quantity, or serial manifest during preparation.
- Bind exactly one return-receipt lineage to each approved return-dispatch batch; a batch must be received in full through one exact approved receipt rather than several partial receipts.
- Compare product identities, quantities, condition, and exact approved return-dispatch serial identities only at the approval boundary, with immutable rejection and blind-reset correction behavior.
- Add inventory at the original transfer location only after approval, preserving good/broken condition and classifying all received stock for the original business.
- For an original PKP business, resolve the applicable tax at receipt-processing time using configured default then deterministic first applicable fallback, fail if none exists, and snapshot the chosen tax identity/name/rate/provenance on the receipt.
- Atomically move exact serialized goods to the original location, reclassify their tax identity, close return-leg custody and active claims, close the batch reservations, and increment returned obligation quantities.
- Recompute the transfer header after each independently approved receipt: remain `RETURN_DISPATCHED` while another batch is in transit, return to `AWAITING_RETURN` when obligations remain without active batches, and become `COMPLETED` only when every obligation is fulfilled and no batch remains unresolved.
- Make submission and approval action-scoped and idempotent, while keeping Delivery 10 reporting/export work out of scope.

## Capabilities

### New Capabilities
- `stock-transfer-return-receipt`: Defines completely blind per-batch recounting, exact approval comparison, origin inventory application, serialized custody closure, and independent receipt lifecycle.

### Modified Capabilities
- `stock-transfer-return-obligations`: Activates atomic reservation closure and returned-quantity fulfillment per approved exact receipt, including final completion rules.
- `stock-transfer-movement-foundation`: Scopes return-receipt attempts and corrections to their approved return-dispatch batch so several batches can be received independently and concurrently.
- `stock-transfer-inventory-movement`: Adds origin-side inventory restoration, processing-time tax classification, exact serial relocation/reclassification, and return-leg custody closure.
- `stock-transfer-system-stock-visibility`: Makes return-receipt preparation universally blind and approval/detail projections permission-aware and non-leaking.
- `stock-transfer-cross-tenant-tax-return`: Defines destination-to-origin tax reclassification at approved return receipt, including processing-time default-tax resolution for PKP origins.

## Impact

- Adds receipt tax-resolution snapshots and any additive lineage/provenance fields required to bind one receipt to one approved return batch.
- Adds return-receipt preparation, scanner, projection, comparison, approval executor, origin-authorized controller/routes, and preparation/review views under `Modules/Adjustment`.
- Extends obligation reservations, movement serial custody, active claims, inventory transactions, histories, and transfer-header projection atomically.
- Reuses existing receive create/approval permissions and `stockTransfers.view-system-stock`; preparation remains blind even for stock-visible users.
- Requires focused migration/model, blind scanner, authorization/projection, exact comparison, inventory/tax, serialized custody, obligation/header, concurrency, idempotency, and rollback verification; no full-suite test plan is required.
