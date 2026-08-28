## Why

Phase 1 consignment custody receiving has several governance gaps that can admit inactive locations, expose cross-setting supplier names, race document submission against edit or deletion, and create duplicate-product receipts whose stored snapshots cannot be reversed deterministically. These defects undermine the custody foundation that Phase 2 sales allocation relies on and should be corrected before the combined workflow is released.

## What Changes

- Make Consignment Receival edit and deletion transactional, header-locked, and status-revalidated so they cannot race submission or another lifecycle action.
- Require active consignment locations in receiving selectors, pending-receiving construction, and approval revalidation.
- Prevent changing a location's consignment classification while stock, active consignment documents, current serial custody, receipt provenance, sold-source evidence, or allocation dependencies remain associated with it.
- Scope Phase 1 supplier filters and supporting queries to the active setting.
- Reject duplicate product lines within one Consignment Receival so receipt snapshots and full reversal remain deterministic.
- Add focused regression coverage for forged requests, tenant isolation, stale lifecycle actions, location governance, duplicate products, and reversal safety.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `consignment-custody-receiving`: Strengthen Receival mutation concurrency, active-location admission and reclassification governance, setting-scoped supplier visibility, and deterministic one-product-per-document receipt/reversal behavior.

## Impact

- Phase 1 Consignment Receival and Receiving controllers and lifecycle/domain services.
- Location administration validation and dependency queries across Consignment, Product Serial Number, and Phase 2 allocation records.
- Consignment custody migrations or database constraints if a safe per-document product uniqueness constraint is adopted.
- Focused Consignment feature and unit tests; no Purchase, payable, inventory valuation, or Phase 2 allocation behavior is otherwise broadened.
