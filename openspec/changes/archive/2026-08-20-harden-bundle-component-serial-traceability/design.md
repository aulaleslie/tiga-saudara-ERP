## Context

The ERP already captures bundle-component serials in the POS cart, persists partial assignments in drafts, validates exact counts, posts component stock and serial state through checkout, and supports serialized dispatch and return infrastructure. Normal Sales also dispatches bundle components using the established product/tax/bundle composite identity. The remaining risk is consistency across adapters and lifecycle boundaries: a serial can occupy a standalone or component position, live state can change after cart assignment, serial movement/history must reconcile with current state, and historical views and returns must preserve the exact component association.

This change spans `Modules/Pos`, `Modules/Sale`, serial history support, receipt/detail projections, and POS Return services. It must preserve existing owner-split, tax, stock, lifecycle-warning, idempotency, and transaction behavior. Current tables and persisted checkout/Sale/dispatch lineage are preferred over new storage.

## Goals / Non-Goals

**Goals:**

- Make serial uniqueness authoritative across standalone, bundle-parent, and bundle-component positions.
- Revalidate component serials against locked current state immediately before posting.
- Reconcile one physical component fulfillment with one stock effect, serial transition, component DispatchDetail, and serial movement/history record.
- Preserve this lineage through historical display and POS Return execution.
- Add focused regression coverage for every implementation path changed by this proposal.

**Non-Goals:**

- Redesign bundle composition, serial inventory, dispatch, or POS Return architecture.
- Reserve serials indefinitely merely because they are stored in an abandoned draft.
- Add recursive bundle expansion or fractional serialized quantities.
- Rewrite or infer missing serial lineage for historical transactions.
- Add a full application test-suite task.

## Decisions

### Use one normalized cart-wide serial assignment set

Parent `assigned_serials` and every `bundle_item_serials` collection will be flattened into a normalized set for duplicate checks. The browser helper and server mutation methods will use equivalent semantics, while the server remains authoritative. Assignment validation remains product-scoped: the selected serial record must belong to the target product, even if printed serial text is reused by another SKU.

Alternative considered: validate only within each line or component. Rejected because the same physical serialized SKU may appear standalone and in multiple bundle positions.

### Treat persisted assignments as intent and locked current serial state as authority

Draft snapshots retain component identity, required quantity, and assigned serial values, but checkout re-resolves each serial record and validates current product, status, dispatch link, location access, reservation, and provenance inside the existing posting transaction/concurrency boundary. Lifecycle acknowledgement cannot bypass this operational gate.

Alternative considered: trust validation performed during assignment. Rejected because stock transfers, other checkouts, returns, and dispatch activity can make an assignment stale.

### Reuse component DispatchDetail as fulfillment lineage

The component DispatchDetail remains the join point between posted component demand, source location, serial current state, stock movement, and movement/history. Split planning must pass the component-specific assigned serials only to the allocation that fulfills them. Posting will call the existing serial movement/history mechanism rather than writing an independent bundle ledger.

Before introducing schema changes, implementation will verify that the existing POS transaction line metadata, SaleBundleItem/SaleDetail, DispatchDetail, ProductSerialNumber, and history records can reconstruct the association. If they cannot, implementation must stop and revise the design rather than silently infer lineage.

Alternative considered: add a bundle-component serial table. Deferred because existing persisted lineage appears sufficient and new duplicated authority would increase reconciliation risk.

### Keep checkout effects atomic and replay-safe

Serial locks, stock deduction, dispatch persistence, current-state transition, movement/history creation, Sale posting, and checkout status remain in the existing database transaction. A matching idempotent replay returns stored results before executing new serial effects. Failures in any later owner group roll back earlier component effects.

Alternative considered: record serial history asynchronously. Rejected because current state and movement history could diverge if queue delivery fails.

### Project historical component serials from posted data

Receipt and transaction-detail builders will expose a component-to-serial projection derived from persisted transaction/Sale/dispatch lineage. Views will render serials beneath the relevant component and keep parent serials separate. They will not query the live bundle definition or use current serial status/location to reconstruct history.

Alternative considered: display all serials at the parent line level. Rejected because it loses component identity when the parent and components are serialized or the same SKU appears in multiple positions.

### Reuse cumulative return eligibility and locking

Bundle-component serial returns will resolve the original component SaleDetail and DispatchDetail, then use the existing consuming-return-state rules. Submission/update must enforce exclusive consumption of a returned serial within the existing atomic lock boundary. Approval/receiving uses existing Sales Return serial history transitions and replacement lineage.

Alternative considered: maintain a bundle-specific return counter. Rejected because serialized identity is singular and the existing return lifecycle already defines consuming states.

### Reject fractional serialized demand operationally

Required serial counts must represent whole physical units. If parent quantity multiplied by component quantity-per-bundle is not a non-negative integer for a currently serial-required, stock-managed component, assignment/finalization will fail with a component-specific validation error rather than rounding demand.

Alternative considered: retain integer rounding. Rejected because rounding can authorize a serial count that does not equal physical component demand.

## Risks / Trade-offs

- [Historical rows may lack reconstructable component serial lineage] → Display only authoritative persisted associations, retain existing historical output as a fallback, and do not fabricate records.
- [Lock ordering across multiple owners can deadlock] → Resolve and lock serials in deterministic identifier order and retain the established stock/checkout lock order.
- [UI and server duplicate messages can drift] → Centralize browser normalization locally and assert API error codes/details in focused feature tests; server behavior remains authoritative.
- [Receipt projections can add queries] → Eager-load transaction lines, serials, bundle composition, and dispatch lineage in bounded collections and add query-sensitive verification only where existing conventions support it.
- [Stricter live validation can reject previously accepted stale drafts] → Return component-specific actionable errors and keep the draft editable so the cashier can replace assignments.
- [Return behavior varies by lifecycle state] → Reuse the existing `consumesReturnQuantity` policy instead of inventing new state lists.

## Migration Plan

1. Implement assignment normalization and focused cart tests.
2. Harden checkout revalidation/posting and movement-history reconciliation with rollback and replay tests.
3. Add historical projections and rendering tests.
4. Harden return lineage/exclusivity with focused lifecycle tests.
5. Run only the focused test files covering touched implementations, plus adjacent existing regression files selected during implementation.

No database migration is planned. Rollback consists of reverting the application changes; existing posted serial, dispatch, movement, and return records remain compatible.

## Open Questions

- Confirm during implementation which existing serial history service is the single supported writer for POS automatic dispatch and normal Sales dispatch.
- Confirm whether old completed POS transactions always retain enough component serial lineage for display; if not, preserve the current display for those records and document the limitation rather than backfilling inferred data.
