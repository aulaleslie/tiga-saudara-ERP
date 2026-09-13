## Why

Mandatory-return transfers currently stop at `AWAITING_RETURN`: their obligations are recorded, but destination operators cannot prepare, approve, or dispatch stock back to the origin. The next delivery must activate the return-dispatch leg without coupling it to return receipt, while supporting practical partial and concurrent shipments.

## What Changes

- Add scanner-first return-dispatch preparation for one or many obligated products, with explicit physical-count confirmation and no requirement to return every outstanding item in one batch.
- Permit multiple independently approved return batches to be in transit concurrently, while atomically preventing their aggregate received, in-transit, and newly approved quantities from exceeding each product/condition obligation.
- Allow serialized products to use eligible substitute serials on the return leg; the approved return-dispatch serial set, rather than the forward serial set, becomes the exact manifest for the later return receipt.
- Add independent draft, submit, approve, reject, cancel, and correction behavior for each return-dispatch attempt.
- Deduct destination inventory and activate return-leg transit custody only after approval, using the committed route policy's destination-side tax classification and preserving immutable inventory, serial, actor, and history provenance.
- Keep obligation fulfillment and origin inventory addition dormant until a corresponding return receipt is independently approved in Delivery 9.
- Apply tenant, location-side, movement-ownership, and stock-visibility authorization boundaries to all operational and projection surfaces.

## Capabilities

### New Capabilities
- `stock-transfer-return-dispatch`: Defines partial multi-product return batches, substitute-serial selection, independent approval, concurrent in-transit manifests, and destination inventory/custody effects.

### Modified Capabilities
- `stock-transfer-return-obligations`: Changes outstanding obligations from a future all-at-once return concept to reservable partial quantities across multiple concurrent approved return-dispatch batches, without marking quantities returned before receipt approval.
- `stock-transfer-movement-foundation`: Activates production-facing `RETURN_DISPATCH` attempts, lifecycle, source-lineage, idempotency, correction, and concurrency requirements for workflow version `2` transfers.
- `stock-transfer-inventory-movement`: Adds atomic destination deduction, transaction provenance, and return-leg serialized transit custody for approved return dispatches.
- `stock-transfer-system-stock-visibility`: Applies blind preparation and permission-aware approval projections to quantities, stock levels, differences, allocations, and other sensitive return-dispatch information.

## Impact

- Adds additive persistence needed to reserve obligation quantities against approved in-transit return batches and to retain exact per-batch inventory and serial provenance.
- Adds return-dispatch preparation, scanning, projection, comparison, approval execution, controller/routes, and operator/reviewer views under `Modules/Adjustment`.
- Extends transfer and obligation status projection to distinguish outstanding work from one or more concurrent return batches in transit; transfer completion remains owned by Delivery 9.
- Reuses existing movement permissions and the `stockTransfers.view-system-stock` boundary rather than introducing broader visibility.
- Requires focused migration/model, preparation/scanner, authorization/projection, approval/inventory, serialized custody, partial/concurrent-obligation, correction, and rollback tests; a full-suite test plan is not required.
