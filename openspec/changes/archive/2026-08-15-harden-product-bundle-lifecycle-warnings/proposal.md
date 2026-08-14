## Why

Bundle lifecycle fields and component availability currently do not have consistent runtime meaning across POS and normal Sales. New transactions can select ineligible bundles, while previously captured drafts can advance without notifying users that the live bundle or one of its components has become inactive, expired, deleted, or otherwise invalid.

## What Changes

- Introduce one setting-scoped runtime eligibility evaluation for bundle enabled state, activation dates, non-empty composition, and live component availability.
- Prevent currently ineligible bundles from being selected for new POS or Sales cart lines, including direct/manual bundle identifiers.
- Detect live bundle and component lifecycle changes when an existing POS or Sales snapshot is loaded or advanced through submission, checkout, approval, or dispatch.
- Present one consolidated acknowledgement prompt instead of blocking an existing captured transaction solely because its live bundle definition or component lifecycle is now ineligible.
- Keep acknowledgement request-scoped and non-persistent; later operations may prompt again.
- After acknowledgement, continue from the persisted POS or Sales bundle snapshot without refreshing composition, quantities, or prices from the live definition.
- Preserve hard blocking behavior for stock, serial, ownership/location, snapshot-integrity, and other operational validation failures.
- Keep completed receipts, reprints, returns, and reports isolated from live lifecycle eligibility.

## Capabilities

### New Capabilities

- `product-bundle-runtime-lifecycle`: Defines setting-scoped eligibility for new bundle selection, acknowledgement warnings for captured POS and Sales snapshots, snapshot-authoritative continuation, and historical-display isolation.

### Modified Capabilities

None.

## Impact

- Product bundle queries and shared resolver behavior used by POS and normal Sales.
- POS product search, barcode scan metadata, bundle selection, cart addition, draft loading, checkout preflight, and finalization requests.
- Normal Sales bundle selection, draft hydration, submission/update, approval, and dispatch actions.
- UI response contracts for consolidated lifecycle warnings and request-scoped acknowledgement.
- Focused feature and Livewire coverage for affected Product, POS, Sales, approval, and dispatch paths; no full-suite verification task is introduced.
- No schema change is required for acknowledgement because it is not stored or audited.
