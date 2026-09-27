## Context

Phase 1 introduced setting-owned consignment locations, approved Consignment Receivals and Receivings, immutable supplier receipt details, serial-to-receipt lineage, custody-safe stock mutation, and reconciliation. Physical stock remains aggregated by product and location; supplier ownership is represented by approved receiving-detail provenance.

Ordinary Sales and POS already converge on approved `dispatch_details`, which persist the product, actual source location, quantity, tax identity, sale relationship, and dispatched serials. POS additionally persists checkout-to-sale/source-location groups. Existing returns link their details to dispatch details, but POS cash-return receiving can reduce `dispatch_details.dispatched_quantity`, and receiving a serialized return clears the serial's current dispatch pointer. Phase 2 must therefore preserve the original sold evidence before using mutable current state for eligibility.

The change crosses Consignment, Sale, POS, Sales Return, Product serial history, permissions, and reporting. It must remain additive, tenant-safe, SQLite-compatible for focused tests, and must not change ordinary dispatch, checkout, inventory, Purchase, or payment execution.

## Goals / Non-Goals

**Goals:**

- Detect the portion of approved Sales and posted POS dispatches actually sourced from consignment locations.
- Preserve immutable, idempotent sold-source evidence for historical and future eligible dispatches.
- Resolve serialized units to exactly one approved supplier receipt and support explicit same-location receipt-lot allocation for non-serialized quantities.
- Reserve allocation capacity at submission and atomically prevent oversubscription on both sold and receipt sides.
- Account for physically received returns before supplier billing while preserving original sale evidence.
- Provide an audited one-supplier confirmation lifecycle and expanded reconciliation that Phase 3 can consume.
- Verify only the new and directly touched behavior with focused tests; no full-suite test task is required.

**Non-Goals:**

- Generating a Purchase, payable, Purchase Payment, supplier invoice, Faktur Pajak, journal, or payment eligibility.
- Changing Sales dispatch location choice, POS location priority, stock mutation, customer pricing, or customer output VAT.
- Post-billing credit/Purchase Return processing, since no bill is created in this phase.
- Bundles, services, non-stock products, imports, partial consignment receiving, transfers, ownership conversion, outbound consignment, agreements, commission pricing, FIFO/LIFO, cross-location pooling, or multi-supplier confirmations.

## Decisions

### 1. Approved dispatch details are the common sold-event source

Use approved `dispatch_details` as the canonical actual-source record for ordinary Sales and POS-generated Sales. Link POS context through `pos_checkout_sales` when present, but do not derive a second quantity from it.

This reuses the point where inventory has actually left a location and prevents current stock or location priority from being re-evaluated. Reading Sale headers alone would lose mixed-location fulfillment; treating POS checkout groups as a separate economic source would duplicate dispatch quantities.

### 2. Materialize an immutable sold-source ledger idempotently

Create a `consignment_sold_sources` row per eligible dispatch detail with a unique `dispatch_detail_id`. Snapshot setting, sale, optional POS checkout/group, product, source location, original base quantity, dispatched time, tax context, serial identities, and a canonical source hash.

Future dispatches may be discovered immediately after approval/posting, while a reconciliation/backfill command or lazy discovery service captures already-existing eligible dispatches. Discovery is repeatable and never rewrites an existing source. For dispatch rows already reduced by POS cash returns, reconstruct the original quantity from the current quantity plus effective linked reductions, then persist and validate the evidence; ambiguous reconstruction is blocked for manual review rather than guessed.

An alternative was to calculate directly from mutable dispatch rows on every page load. That is rejected because POS return receiving changes quantities and returned serials lose their current dispatch pointer.

### 3. Model one supplier per confirmation with separate sold and receipt allocations

Add confirmation headers with `DRAFT`, `WAITING_APPROVAL`, `APPROVED`, and `REJECTED` states. A header belongs to one setting and one supplier. Lines link a sold source to one or more approved Consignment Receiving details from that supplier and the same location.

Each receipt allocation snapshots base quantity, unit cost, unit DPP, tax identity/rate/amount basis, receival and receiving references, and supplier identity. This allows one non-serialized sold quantity to consume multiple same-supplier lots when their commercial snapshots differ.

One supplier per confirmation is preferred over a multi-supplier batch because approval and the future Phase 3 Purchase boundary are supplier-scoped.

### 4. Serialized allocation is exact and non-editable

For each sold serial, resolve its immutable approved consignment receiving-detail pivot/history, not only the serial's mutable current source columns. The receiving detail must belong to the sold product, source location, active approved receiving, confirmation setting, and confirmation supplier. A serial with missing, reversed, conflicting, or ambiguous lineage is a blocker.

Persist one allocation identity per sold serial and enforce uniqueness across active/approved confirmations. Operators may include or exclude an eligible serialized unit, but cannot select a different supplier or receipt lot.

### 5. Non-serialized allocation is explicit and bounded by two ledgers

Operators enter quantities against eligible approved receiving-detail lots for the selected supplier and exact source location. The system does not silently choose FIFO/LIFO or substitute another receipt.

At submission and approval, enforce:

```text
requested sold allocation
<= original sold quantity - effective returns - approved allocations - other active reservations

requested receipt allocation
<= approved received quantity - reversal - approved allocations - other active reservations
```

Use decimal base quantities matching receiving precision, even where current Sale dispatch paths use whole quantities, so the ledger does not prevent future UOM-safe evolution. All UI quantities are normalized to base quantity before persistence.

### 6. Submission creates reservations; drafts do not

Drafts are editable planning records and do not consume capacity. Submitting a valid confirmation atomically creates hard reservations and moves it to `WAITING_APPROVAL`. Approval converts those reservations to immutable approved allocations; rejection releases them. A rejected confirmation may be revised and resubmitted with previous rejection evidence retained.

This avoids abandoned drafts blocking quantities while still preventing two pending approvals from claiming the same capacity. Explicit cancellation is unnecessary for this phase because rejected submissions release capacity and deletions are limited to drafts.

### 7. Effective pre-billing returns are physically received returns

Deduct only Sales Return details whose parent return has reached `AWAITING SETTLEMENT` or `COMPLETED`, including POS Returns executed through linked Sales Returns. Pending, rejected, archived without execution, or merely requested returns do not reduce eligibility.

Return deductions are resolved by `dispatch_detail_id`; serialized deductions also use the returned serial IDs/history. Approval rechecks current effective returns under lock. If a received return makes a pending confirmation exceed capacity, approval fails and the confirmation must be revised. Phase 2 blocks new approval against any quantity already returned, but does not implement post-billing credits.

### 8. Use canonical snapshots and stale-source validation

Discovery and confirmation submission store canonical JSON snapshots plus hashes. Approval locks authoritative records and compares product, location, supplier lineage, quantities, return deductions, receipt status, and snapshots. A mismatch rejects approval with actionable blockers; it never substitutes a supplier or silently recalculates an approved decision.

### 9. Lock in a stable order and enforce database idempotency

Lifecycle services use one database transaction and lock, in ascending ID order: confirmation header, sold sources/dispatches, receiving details/receivings, serialized identities, and competing reservations/allocations. Unique keys protect one sold-source row per dispatch, one active serialized claim per serial, and idempotent confirmation references. Aggregate capacity remains service-validated under locks because partial decimal allocations cannot be expressed as a simple unique constraint.

### 10. Keep Phase 2 financially inert

Approval records supplier allocation only. It creates no Purchase or other payable/payment record and performs no inventory, cost, tax, serial-status, Sales, POS, or return mutation. Phase 3 will consume approved allocations through explicit nullable linkage and status fields designed additively here.

### 11. Extend existing reconciliation rather than add a second report

Expand the current custody reconciliation with received, reversed, sold, effective returned-before-billing, pending-reserved, approved-allocated, and remaining receipt-pool quantities. Provide filters by setting, supplier, product, location, sale/POS source, confirmation status, and serial. Totals are derived from immutable event/allocation rows rather than mutable balance columns.

### 12. Add least-privilege permissions and focused verification

Add separate permissions for allocation access, create/edit, submit, approve, and reject. Every query/action enforces active setting boundaries in both controller and service layers.

Verification targets new migrations/models, discovery, Sales/POS source mapping, serialized lineage, manual non-serial allocation, return deductions, lifecycle, concurrency/rollback, permissions/tenancy, financial and inventory non-mutation, and reconciliation. Run only focused Consignment and directly affected Sales/POS/return tests; do not require `composer test:fresh-sqlite` or the complete application suite for this change.

## Risks / Trade-offs

- **Historical dispatches may already have mutable POS return adjustments** → Reconstruct from linked executed return evidence, persist blockers for ambiguity, and require a reconciliation preview before enabling approval.
- **Receipt-pool sums can race** → Lock sold sources, receiving details, and active allocations in deterministic order and revalidate both capacity equations within submission/approval transactions.
- **Serial current pointers are cleared on return** → Resolve original ownership and sale identity from immutable receiving pivots and serial history, with current fields only as corroboration.
- **One dispatch could contain unsupported bundle rows** → Exclude bundle/component contexts with an explicit reason and reconciliation count; bundle allocation remains deferred.
- **Phase 2 can approve allocations that cannot yet be paid** → Label them “ready for billing,” expose no payment action, and require Phase 3 before operational financial release.
- **Derived totals may be slower than balance columns** → Add source/status/location/product/supplier indexes and aggregate in bounded, paginated queries; prefer correctness over drift-prone counters.
- **Decimal receipt quantities meet integer dispatch paths** → Normalize all allocation arithmetic to decimal base units and reject mismatched or unverifiable UOM evidence.

## Migration Plan

1. Add nullable/additive sold-source, confirmation, allocation, receipt-allocation, serial-claim, reservation/audit, and source-hash tables/columns with indexes and foreign-key restrictions; do not backfill during migration.
2. Deploy models/services and a read-only discovery/reconciliation preview. Run idempotent historical discovery for approved dispatches at consignment locations and review ambiguous blockers.
3. Enable confirmation create/submit/approve actions through dedicated permissions after focused verification and reconciliation sign-off.
4. Keep approved confirmations financially inert until the separate Phase 3 change is implemented.

Rollback disables Phase 2 routes/permissions first. Additive tables may remain for audit; dropping them is safe only when no confirmations exist. No rollback rewrites Sales, POS, return, receiving, stock, serial, Purchase, or payment records.

## Open Questions

None blocking. The specification adopts the latest urgent-delivery decisions: one supplier per confirmation, reservation at submission, approved dispatch/POS completion as the sold event, and physically received returns as the pre-billing deduction boundary.
