## Why

Stock transfers currently collapse dispatch or receipt authorization and inventory mutation into a single action on the transfer header. Independent physical counts, approval revisions, normalized serial manifests, and later blind dispatch/receipt workflows require an additive movement-document foundation before any production action can safely move to the new lifecycle.

## What Changes

- Add a versioned stock-transfer movement aggregate for forward dispatch, forward receipt, return dispatch, and return receipt attempts.
- Persist movement lines and serial selections as normalized, base-unit records tied to an exact approved transfer revision and, where applicable, an exact source movement.
- Define draft, pending, approved, rejected, and cancelled movement states with immutable submitted revisions, correction through superseding revisions, actor/timestamp audit fields, and scoped idempotency.
- Add explicit operational source/destination locations and constraints that prevent competing open or approved attempts for the same transfer and movement type.
- Add a transfer workflow-version boundary so legacy transfers remain governed by their existing header lifecycle while future transfers can opt into movement-authoritative processing.
- Register separate dispatch/receipt preparation and approval permissions, with compatibility assignment from legacy dispatch/receive permissions and no implied stock-visibility grant.
- Establish storage for serialized in-transit custody without activating it or changing serial locations in this delivery.
- Keep the foundation operationally dormant: no production movement routes, user interfaces, inventory mutations, tax reclassification, return-policy replacement, serial-custody activation, or header-status derivation are introduced yet.
- Add focused migration, aggregate, authorization, revision, constraint, idempotency, and legacy-compatibility verification.

## Capabilities

### New Capabilities

- `stock-transfer-movement-foundation`: Defines versioned movement attempts, normalized lines and serials, lifecycle invariants, workflow-version compatibility, dormant transit-custody data, and preparation/approval permissions.

### Modified Capabilities

None. Existing stock-transfer lifecycle, inventory movement, cross-tenant return, scanning, and visibility behavior remains operationally unchanged until later delivery changes explicitly modify those capabilities.

## Impact

- Adds migration-safe tables and indexes under `Modules/Adjustment` and additive workflow-version data on transfers.
- Adds Eloquent entities/relationships and domain services for dormant movement-document lifecycle operations.
- Extends `app/Config/Permissions.php` and permission synchronization/migration behavior with four movement permissions.
- Preserves existing transfer routes, controllers, Livewire screens, header statuses, inventory transactions, serial movement, and legacy permission checks.
- Establishes contracts consumed by later forward dispatch, blind receipt, PKP route-policy, and return-movement changes.
