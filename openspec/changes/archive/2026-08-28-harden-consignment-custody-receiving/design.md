## Context

Phase 1 introduced custody-only receiving, location classification, immutable supplier receipt lots, serialized lineage, weighted operational cost, and controlled reversal. A combined Phase 1/Phase 2 review found that the core receiving and allocation transactions are sound, but several entry-point and governance seams are weaker than the archived contract: Receival edits and deletions use stale controller-loaded state, inactive consignment locations remain selectable, location reclassification checks only positive stock, one index query is not tenant-scoped, and duplicate product lines create per-detail snapshots that cannot all match during reversal.

The correction spans Setting and Consignment code and must preserve archived custody history, Phase 2 sold-source evidence, ordinary Purchase receiving behavior, and existing database data.

## Goals / Non-Goals

**Goals:**

- Serialize Receival edit, deletion, and submission on the same header row and revalidate state under lock.
- Enforce active, setting-owned consignment locations at UI, creation, and approval boundaries.
- Treat location classification as historical governance and reject changes while any relevant custody or allocation dependency exists.
- Remove cross-setting supplier-name exposure from Phase 1 list filters.
- Guarantee deterministic receipt and reversal snapshots by allowing at most one line per product per Receival.
- Add focused tests for every corrected invariant without running or planning an unrelated full application suite.

**Non-Goals:**

- Changing Phase 2 allocation formulas or lifecycle behavior.
- Supporting partial receiving, partial reversal, duplicate product lots in one Receival, ownership conversion, billing, or payments.
- Reclassifying or rewriting existing custody history automatically.
- Introducing a new location type system or changing ordinary-location semantics.

## Decisions

### 1. Move Receival edit and deletion behind locked domain operations

Add service methods that open a database transaction, re-fetch the Receival with `lockForUpdate()`, revalidate its authoritative state and receiving dependencies, then update/replace lines or delete the draft. Controllers continue to enforce permissions and active-setting lookup, but no longer make the decisive lifecycle check.

This mirrors submission locking and prevents stale request models from mutating a header after another request submits it. Optimistic version columns were considered but rejected because the module already uses pessimistic lifecycle locking.

### 2. Apply active-location admission at every boundary

Selectors use `active()->consignment()` with the active setting. Pending-receiving creation and receiving approval independently require matching setting, `is_active = true`, and `is_consignment = true` under the authoritative location lock.

UI-only filtering was rejected because forged requests and locations deactivated between capture and approval must still fail safely.

### 3. Centralize consignment classification dependency checks

Introduce a reusable query/service used by Location update validation. A classification change is blocked when the location has non-zero stock buckets, pending or approved Consignment Receiving records, active consignment serials, immutable receipt provenance, discovered sold sources, confirmation allocations, or other unresolved consignment history whose interpretation depends on the classification.

The check remains conservative: administrators archive or resolve dependent workflows instead of rewriting history. Checking only current stock was rejected because zero stock does not erase provenance or pending work.

### 4. Enforce one product line per Receival

Reject duplicate `product_id` values during normalization with an actionable validation error. Add a database unique constraint on `(consignment_receival_id, product_id)` only after a read-only duplicate preflight confirms existing data is clean; otherwise stop migration and report affected documents for manual remediation.

Aggregation was considered but rejected because duplicate rows may carry different unit cost, tax, UOM, notes, or serial expectations. One canonical line preserves unambiguous snapshots and reversal behavior.

### 5. Keep tenant filtering defense-in-depth

Every supplier collection rendered by Phase 1 is scoped with the session setting, while create/update validation retains setting-qualified existence rules. Domain operations continue to derive authoritative setting identity from the locked document.

### 6. Verify only affected behavior

Add focused feature and unit tests for locked mutation state checks, inactive/foreign location rejection, classification dependencies, supplier list isolation, duplicate-line rejection, and successful reversal after the uniqueness rule. Retain existing Consignment custody, governance, and Phase 2 allocation suites as regression gates.

## Risks / Trade-offs

- [Existing duplicate product lines prevent adding the unique constraint] → Run a read-only preflight and fail deployment with document IDs instead of silently merging commercial evidence.
- [Conservative classification checks can prevent an administrator change after reversal] → Preserve historical meaning by design; provide actionable dependency categories and IDs.
- [Additional locks can increase contention] → Lock only the targeted Receival or Location and use the same ordering already used by lifecycle services.
- [Location state changes between pending capture and approval] → Approval revalidation intentionally rejects and leaves the note pending for operator resolution.
- [Cross-module dependency queries become brittle] → Encapsulate them in one service and cover Phase 1 and Phase 2 dependency types explicitly.

## Migration Plan

1. Add focused tests reproducing each confirmed defect.
2. Implement locked Receival update/delete operations and switch controllers to them.
3. Add active-location filtering and transactional validation.
4. Add centralized classification dependency validation and tenant-scoped supplier queries.
5. Add duplicate-line validation, run a read-only production duplicate preflight, then add the composite unique constraint when clean.
6. Run the affected Consignment custody, governance, Phase 2 allocation, Purchase receiving, and valuation tests.

Rollback removes the new database uniqueness constraint and restores application code only if no dependent operations have been performed. It must not alter existing Receival, receipt, serial, sold-source, or allocation history.

## Open Questions

- None blocking. The conservative rule is to retain consignment classification whenever immutable custody or allocation provenance exists.
