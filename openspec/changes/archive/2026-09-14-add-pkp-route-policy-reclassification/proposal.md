## Why

Workflow version `2` currently completes only same-business and cross-business non-PKP-to-non-PKP transfers. PKP-involved routes need an immutable approval-time policy, destination-based tax reclassification, and full-quantity return obligations before they can safely use the approved dispatch and blind receipt workflow.

## What Changes

- Snapshot origin/destination business identity, PKP status, same-business status, stock condition, destination classification, mandatory-return decision, applicable tax identity, and approved transfer revision when a new transfer is approved.
- Enable workflow version `2` prospectively for PKP-involved routes once the policy snapshot is committed; do not reinterpret or backfill existing transfers.
- Reclassify every approved forward-receipt quantity into the destination business's tax or non-tax bucket while preserving good/broken condition and immutable source/before/after provenance.
- Update received serial tax identity consistently, using the snapshotted destination default tax or deterministic first applicable tax for PKP destinations and `null` for non-PKP destinations, while retaining prior tax identity in immutable history.
- Create one full-quantity return obligation per received product for cross-business routes involving any PKP business, independent of the stock's source tax bucket.
- Project mandatory-return transfers to `AWAITING_RETURN`; continue completing same-business and cross-business non-PKP-to-non-PKP transfers after exact forward receipt.
- Keep return-dispatch and return-receipt operational surfaces outside this change for Deliveries 8 and 9.

## Capabilities

### New Capabilities
- `stock-transfer-route-policy`: Defines immutable approval-time route-policy and tax-resolution snapshots for new workflow version `2` transfers.
- `stock-transfer-return-obligations`: Defines full-quantity product obligations created by approved forward receipt for mandatory-return routes.

### Modified Capabilities
- `stock-transfer-cross-tenant-tax-return`: Replaces taxed-only and exact-original-serial obligation rules with the agreed full-quantity PKP route matrix while leaving return execution to later deliveries.
- `stock-transfer-inventory-movement`: Changes forward receipt from preserving dispatch tax buckets to destination-business reclassification and adds atomic obligation/header effects.
- `stock-transfer-movement-foundation`: Enables prospective workflow version `2` assignment for PKP-involved routes after route-policy support exists.

## Impact

- Adds migration-safe route-policy snapshot and return-obligation persistence under `Modules/Adjustment` and relationships/casts on transfer movement entities.
- Extends transfer approval/version selection and forward-receipt approval execution, including destination stock, global totals, inventory transactions, serialized tax provenance, history, idempotency, and status projection.
- Supersedes conflicting existing requirements that derive obligations only from taxed dispatch quantities or bind obligations to the original forward serial.
- Requires focused tests for the five route classes, good/broken stock, serialized/non-serialized reclassification, tax fallback, obligations, atomic rollback, concurrency, authorization, and prospective compatibility.
