## Why

Bundle-component serial capture and posting now work in the primary POS and normal Sales paths, but the behavior is not yet proven across duplicate positions, state changes before finalization, movement-history reconciliation, retries, returns, and historical operator views. Narrow hardening is needed so a serialized SKU sold standalone and used inside a bundle remains unique, traceable, and reversible without redesigning the bundle or serial subsystems.

## What Changes

- Enforce and verify cart-wide uniqueness for parent/standalone and bundle-component serial assignments, with immediate component-aware POS feedback and authoritative server validation.
- Revalidate assigned bundle-component serial ownership, location, availability, reservation, and classification at checkout finalization under the existing atomic and idempotent posting boundary.
- Ensure each fulfilled bundle-component serial produces exactly one dispatch-linked serial state transition and movement/history record, including correct rollback and replay behavior.
- Preserve component serial lineage through POS return eligibility, submission, approval, receiving, and replacement processing, preventing the same serial from being consumed by two active returns.
- Display component serial assignments alongside their bundle components in completed POS receipts and transaction details.
- Add focused regression coverage for the touched Sales, POS, dispatch, receipt/detail, and return paths; do not add a full-suite test task.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `pos-bundle-component-serial-entry`: Extend component assignment requirements to cover cart-wide duplicate feedback, live revalidation, and mixed parent/component serial combinations.
- `pos-checkout-serial-stock-validation`: Require bundle-component serial posting to reconcile atomically and idempotently with dispatch, current serial state, and serial movement/history.
- `pos-receipt`: Display persisted bundle-component serials under the component to which each serial belongs.
- `pos-transaction-detail-bundle-display`: Display persisted component serial lineage with bundle composition in transaction detail.
- `pos-return-approval-execution`: Preserve and exclusively consume bundle-component serial lineage through return execution and reversal.

## Impact

Affected areas include the POS cart UI and cart service, checkout fulfillability and posting adapters, split planning, serial and dispatch history services, receipt and transaction-detail projections/views, and POS Return snapshot/submission/approval services. Existing cart, Sale, dispatch, serial, and return tables remain authoritative; no schema redesign or historical rewrite is planned unless implementation investigation proves persisted component lineage is unavailable.
