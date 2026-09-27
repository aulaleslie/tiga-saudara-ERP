## Why

Workflow version `2` can now deduct origin inventory and place serialized goods into exclusive transit custody, but it cannot be safely activated without an independent destination recount and approval boundary. A blind forward-receipt workflow is needed so destination users record what physically arrived without being biased by the dispatch manifest, while inventory and custody change only after exact authoritative approval.

## What Changes

- Add destination-side creation, scanning/search, explicit empty-count confirmation, editing, submission, review, rejection, correction, and approval for `FORWARD_RECEIPT` movement attempts sourced from the immutable approved forward dispatch.
- Make receipt preparation completely blind for every preparer: start with no expected lines and never expose the dispatch manifest, expected products, quantities, serials, allocation, stock, or differences during counting.
- Retain unexpected products and substitute serials as physical observations, permit partial or empty recount submission, and compare only after submission against the approved dispatch manifest.
- Require exact product quantities, stock condition, and normalized serial sets for approval; mismatches remain pending until explicitly rejected and corrected.
- Add destination inventory only on receipt approval, using immutable dispatched bucket provenance for Delivery 6's eligible routes.
- Atomically move received serials to the destination, close transit custody, remove active claims, persist receipt inventory references and snapshots, approve the movement, and project the transfer header to `COMPLETED`.
- Activate workflow version `2` only for same-business and non-PKP-to-non-PKP routes; keep PKP-involved routes on version `1` until Delivery 7 supplies route-policy snapshots, reclassification, and return obligations.
- Treat the system as forward-only: no legacy stock-transfer transaction backfill or stored-transfer compatibility migration is required because stock transfer has not been operationally used.
- Add focused migration, domain, authorization, payload-leakage, scanner, comparison, inventory, custody, idempotency, rollback, and activation tests rather than requiring the full suite.

## Capabilities

### New Capabilities

- `stock-transfer-forward-receipt`: Blind destination recount, immutable receipt attempts, exact dispatch comparison, and atomic receipt approval.

### Modified Capabilities

- `stock-transfer-movement-foundation`: Expose `FORWARD_RECEIPT`, support document-level confirmation of an intentionally empty recount, close serialized custody on approved receipt, and expand the gated version `2` lifecycle.
- `stock-transfer-system-stock-visibility`: Require completely blind receipt preparation for all preparers while retaining neutral or privileged approval projections.
- `stock-transfer-inventory-movement`: Make approved forward receipt the atomic destination-addition boundary using dispatched provenance and serial custody closure.
- `stock-transfer-forward-dispatch`: Replace the Delivery 5 activation hold with route-limited version `2` activation once matching receipt is available.

## Impact

- Adds destination-side movement services, comparator/projection logic, authorization wrappers, routes, and Livewire or Blade interaction surfaces under `Modules/Adjustment`.
- Extends movement persistence with empty-count confirmation and immutable receipt application provenance where existing line fields are insufficient.
- Updates destination `ProductStock`, global `Product` totals, inventory `Transaction` records, live `ProductSerialNumber` locations/history, movement serial custody, active serial claims, movement history, and transfer header projection within a locked transaction.
- Introduces route eligibility gating based on same-business and current PKP/non-PKP status without yet persisting Delivery 7's historical route-policy snapshot or creating return obligations.
- Preserves legacy workflow version `1` for all PKP-involved routes and keeps return movement types operationally dormant.
