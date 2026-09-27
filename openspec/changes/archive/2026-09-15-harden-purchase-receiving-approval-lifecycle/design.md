## Context

Purchase receiving approval currently performs stock, price, transaction, serial, receiving-note, and Purchase-header writes in one database transaction and uses row locks for the receiving note and Purchase. It correctly derives `RECEIVED` or `RECEIVED PARTIALLY` from approved quantities. The generic Purchase status endpoint, however, accepts a requested target status without validating the authoritative source status under a lock. A stale approval form can therefore overwrite a received Purchase back to `APPROVED` after the receiving transaction commits.

The ordinary Purchase edit endpoint also determines editability before its transaction and accepts a status field among generic edits. Receiving creation permits zero-quantity placeholders when another row is positive; that aggregate invariant must be revalidated when approval actually executes. The existing shortfall-completion workflow already depends on `RECEIVED PARTIALLY` and must remain compatible.

## Goals / Non-Goals

**Goals:**

- Make approved receiving evidence and the Purchase header status consistent at every committed boundary.
- Serialize competing Purchase lifecycle, receiving approval, shortfall completion, and full-edit operations around an authoritative locked Purchase row.
- Require at least one strictly positive receiving row at approval while retaining valid zero-quantity placeholders.
- Make receiving approval atomic and idempotent across stock, transactions, serials, histories, status, audit evidence, and transactional notifications.
- Reject malformed or stale operations with actionable validation without partially mutating inventory.
- Cover the changed behavior with focused tests only.

**Non-Goals:**

- Changing purchase costing, pricing, payment, return, UOM, or shortfall normalization formulas.
- Disallowing zero-quantity receiving rows when the same receiving has a positive row.
- Repairing historical Purchase headers automatically.
- Reworking unrelated Purchase UI or running the full application test suite as an acceptance requirement.

## Decisions

### Centralize authoritative Purchase lifecycle transitions

Introduce or extend a Purchase lifecycle service used by the generic status endpoint. It will accept an intended action rather than trusting an arbitrary client-supplied lifecycle result, start a database transaction, lock and reload the Purchase, authorize the action, validate the current-to-target transition, and persist it. The permitted user-driven transitions are `DRAFTED -> WAITING_APPROVAL`, `WAITING_APPROVAL -> APPROVED`, `WAITING_APPROVAL -> REJECTED`, and `REJECTED -> DRAFTED`.

`RECEIVED PARTIALLY` and `RECEIVED` are derived states owned exclusively by receiving approval and shortfall completion. Any generic request against a Purchase that has positive approved receiving evidence will be rejected if it could leave or move the header to `APPROVED`.

Validating only the target status was rejected because it cannot distinguish a legitimate approval from a replayed stale form. Optimistic UI hiding was rejected as an integrity mechanism because multiple tabs and users can retain old forms.

### Use the Purchase row as the lifecycle serialization root

Every mutation that can change Purchase lifecycle state or replace commercial lines will lock and reload the Purchase before evaluating eligibility. Related rows will use a consistent order: Purchase, Purchase details, receiving notes, receiving details, products, product stocks, then serials. Receiving approval may retain its cache lock as an optimization, but correctness will depend only on the database transaction and row locks.

This aligns receiving approval with shortfall completion and closes the generic-edit race. Relying only on the existing receiving-note lock was rejected because it does not serialize an independent Purchase status or edit request.

### Revalidate receiving eligibility inside approval

After locking, approval will require an unarchived ordinary Purchase in `APPROVED` or `RECEIVED PARTIALLY`, a `PENDING` receiving note belonging to it, an eligible active standard location in the same setting, intact detail relationships, and at least one receiving detail whose canonical quantity is strictly positive. Zero rows remain present for receipt evidence but contribute neither delivery progress nor stock.

Missing Purchase details will fail approval rather than be skipped. Serialized quantities and serial identity/state will be revalidated at the mutation boundary. Any failure rolls back the complete operation.

### Derive the Purchase status from approved quantities

Within the approval transaction, after the receiving note becomes `APPROVED`, cumulative quantities will be calculated only from approved receiving details using the existing decimal-safe quantity service. If every Purchase line is cumulatively fulfilled, the header becomes `RECEIVED`; otherwise the existence of the newly approved positive quantity makes it `RECEIVED PARTIALLY`.

The service will not accept a target Purchase status from the client. Persisted header status is an output of approved evidence. Reconciliation of unrelated historical rows is excluded from this change.

### Make approval idempotent and atomic

The locked `PENDING` check is the idempotency boundary. Only the request that observes `PENDING` under lock may post stock or approve the note. A replay that observes `APPROVED` or `REJECTED` returns an already-processed conflict and performs no mutation.

Stock increments, tax buckets, product totals, price updates already coupled to approval, `BUY` transactions, serial records and pivots, serial history, receiving status, Purchase status, and database-backed approval notifications remain in the same transaction. External or queued notification delivery, if any, will be dispatched after commit so rollback cannot produce a false success notification.

### Persist explicit status-transition audit evidence

Persist an immutable Purchase status-transition audit for successful user-driven transitions and receiving-derived transitions. Evidence will include Purchase and setting identifiers, optional receiving-note identifier, previous and resulting status, action/source, actor, and timestamp. Use an explicit Purchase-domain audit record rather than assuming the existing generic `audits` table captures these models; current Purchase updates do not produce reliable generic audit rows.

Audit creation for a receiving-derived transition occurs in the approval transaction. A failure to persist required audit evidence fails the operation atomically. Historical transitions are not backfilled.

### Keep controllers and UI thin

Controllers will delegate transition and approval decisions to services and translate domain conflicts into the existing redirect or JSON response conventions. UI status conditions remain useful guidance, but server-side locked validation is authoritative. The full Purchase edit path will no longer accept status as generic edit data and will repeat its edit-mode check on the locked, refreshed Purchase before replacing details.

## Risks / Trade-offs

- [A consistent lock order touches several existing mutation paths] -> Limit changes to paths that can race over Purchase lifecycle or commercial lines and add focused concurrency/rollback tests.
- [Long approval work holds the Purchase lock while stock, serial, and price effects are calculated] -> Preserve the existing transaction boundary because atomic inventory correctness is more important than reducing lock duration; keep queries bounded to affected rows.
- [An explicit audit record adds schema and write overhead] -> Store a compact immutable transition record with indexed Purchase and receiving identifiers; do not snapshot unrelated commercial data.
- [After-commit notification behavior can differ from current synchronous behavior] -> Change only external/queued delivery timing while retaining transactional notification state needed by the application.
- [Existing inconsistent historical headers remain] -> Provide a separate read-only reconciliation query or operational procedure if requested; do not silently rewrite history during deployment.
- [Deadlocks can still occur under unrelated inventory operations] -> Use deterministic row ordering and rely on bounded transaction retry only where the application already supports it.

## Migration Plan

1. Add the Purchase status-transition audit persistence and model/service support.
2. Deploy locked lifecycle transition rules and remove generic status persistence from full Purchase edits.
3. Refactor receiving approval to enforce the positive-row invariant, strict relationship validation, consistent lock order, authoritative derived status, and audit creation.
4. Run focused Purchase lifecycle and receiving approval tests, including deterministic stale/concurrent scenarios and rollback assertions.
5. Optionally run a read-only production reconciliation report for Purchases whose approved quantities disagree with their headers; correct historical cases through an explicit operational decision.

Rollback may remove the new service wiring and retain the additive audit table. Audit rows already written must not be deleted during an application rollback.

## Open Questions

None.
