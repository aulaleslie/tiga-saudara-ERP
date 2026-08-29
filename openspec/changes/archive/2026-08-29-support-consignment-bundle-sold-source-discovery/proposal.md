## Why

Consignment sold-source discovery currently classifies every approved bundle-associated dispatch detail as unsupported, even when that detail is an authoritative stock-managed movement from a consignment location. POS compounds the issue by leaving `is_inventory_managed` null on both inventory and audit-only dispatch rows, so valid bundle sales remain blocked and cannot enter supplier allocation or billing.

## What Changes

- Treat an approved bundle-associated dispatch detail as eligible when it represents a real stock-managed movement from an active consignment location; `bundle_id` alone will no longer be a blocker.
- Keep service, non-stock, missing-product, ambiguous-return, and non-authoritative movement evidence blocked with actionable reasons.
- Make POS persist explicit inventory-management classification on newly created stock and audit-only dispatch details.
- Apply the same eligibility classification in discovery preview and persisted discovery, including a safe compatibility rule for historical POS rows whose classification is null.
- Preserve bundle identity and inventory classification in newly versioned sold-source snapshots and canonical hashes while continuing to validate historical unversioned hashes with their original payload shape.
- Verify ordinary Sales and POS bundle parents/components, serialized lineage, return reconstruction, idempotency, and absence of duplicate physical quantity.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `consignment-sales-allocation`: Stock-managed bundle dispatch details become supported sold sources, blocker classification becomes evidence-based, and new source snapshots preserve bundle movement identity without invalidating historical hashes.
- `pos-checkout-split-posting`: POS posting explicitly persists whether each generated dispatch detail is inventory-managed so downstream custody and allocation workflows do not need to guess from nullable evidence.

## Impact

- Affects `ConsignmentSoldSourceDiscoveryService`, live canonical hash revalidation in the Phase 2 lifecycle, POS dispatch-detail creation, and focused Consignment/POS/Sale bundle tests.
- Adds no inventory, Sale, Dispatch, allocation, or Purchase mutation beyond existing posting behavior; the change only classifies and snapshots already-authoritative physical movements correctly.
- Existing sold sources, confirmations, Purchases, and unversioned hashes remain valid and immutable.
