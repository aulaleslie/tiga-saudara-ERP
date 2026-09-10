## Context

Breakage adjustments already persist an `Adjustment` with `AdjustedProduct` rows and, on approval, decrement location good stock, increment location broken stock, mark selected serials broken, and write inventory transactions. Entry and review remain legacy: create/edit use an older location autocomplete and purchase-oriented selectors, expose separate tax/non-tax quantities, perform weaker serial validation before approval, and show approvers live stock without a precise projected or immutable applied result.

The redesigned Stock Adjustment flow provides suitable interaction patterns—searchable standard-location selection, unified product/conversion/serial scanning, product search, ambiguity handling, focus restoration, reconciliation previews, row locking, and `approval_result` audit persistence—but its full-count semantics are not valid for breakage. Breakage is a delta at one location and must never create/move serials, alter tax classification, or change total physical quantity.

## Goals / Non-Goals

**Goals:**

- Give breakage create and edit the established searchable and scanner-friendly adjustment experience.
- Represent entry as one base-unit breakage quantity per product.
- Derive the sole legal tax bucket from the selected location's current `Setting::is_pkp` value.
- Restrict serialized lines to existing sellable serials at the selected location and derive their quantity from selected serial count.
- Provide an accurate, permission-aware approval preview with explicit conflicts and an immutable applied result.
- Apply every line atomically under database locks while leaving total physical quantity and tax classification unchanged.

**Non-Goals:**

- Redesigning the normal Stock Adjustment/Stock Opname workflow.
- Supporting consignment locations, cross-location serial movement, tax reclassification, new serial registration, disposal, repair, or restoring broken stock to good.
- Reserving stock or serials while a breakage document is pending.
- Running the complete application test suite; focused automated checks and human browser verification are sufficient.

## Decisions

### Use a breakage-specific editor backed by shared entry services

The breakage table will adopt or extract reusable Stock Adjustment capabilities for location events, product search, scan resolution, ambiguous-match selection, and scanner focus. Its state and mutation rules remain breakage-specific. This avoids duplicating mature interaction behavior without adding a breakage mode throughout the full-count component.

Alternative: reuse `AdjustmentProductTable` directly with a mode flag. Rejected because its good/bad toggle, absolute-count draft schema, unknown serial support, and cross-location reconciliation would create a broad conditional surface and make breakage invariants harder to audit.

### Treat the selected location as the authoritative context

Create and edit use the searchable standard-location dropdown and dispatch selection to the breakage editor. Product entry requires a location. Confirmed location changes clear all location-dependent rows, quantities, selected serials, errors, and preview state. Server operations reload the location, verify active-setting ownership, exclude consignment locations, and derive PKP from the location's setting rather than client input or session assumptions.

### Use one displayed breakage quantity and derive its storage bucket

The browser submits one base-unit quantity for a non-serialized product. For a PKP location, the server stores and posts it exclusively as `quantity_tax`; for a Non-PKP location it uses `quantity_non_tax`. The other bucket is zero. Existing columns remain for compatibility, but users cannot choose or convert a tax bucket.

For serialized products, quantity is read-only and equals the count of selected valid serial IDs. The same location PKP rule determines the document and stock bucket. Serial `tax_id` is validation evidence, not an allocation choice: inconsistent legacy serial classification blocks submission/approval and is never silently corrected.

Alternative: keep separate tax/non-tax inputs and infer serial allocation from `tax_id`. Rejected because breakage changes physical condition only and the business PKP setting already determines the valid bucket.

### Resolve scans with a strict breakage policy

The main scan input resolves primary product barcodes, conversion barcodes, and existing serial numbers, with an ambiguity dialog when necessary. A non-serialized product barcode increments breakage by one; an integer-compatible conversion barcode increments by its base-unit factor. A serialized product/conversion barcode only adds or focuses its row. A serial scan adds one unit only if the serial belongs to that product and selected location and satisfies the canonical sellable scope. Unknown serials are never created.

Search returns active, stock-managed products; the product catalogue is global, so `Product.setting_id` never filters or gates results. Availability is expressed entirely through the selected, active-setting-owned location's stock: a matched product with no stock row (or zero good stock) at that location is still selectable, but reports zero available good and rejects any increment as insufficient. Duplicate product selection focuses or reports the existing row without resetting it. Duplicate serial selection changes nothing and shows feedback.

### Preview current consequences but revalidate under approval locks

A dedicated breakage review/planning service will calculate, per product, current good/broken quantities, requested good-to-broken movement, projected result, drift/conflicts, and serial `Good → Broken` transitions. Pending previews are informative and may become stale. Any conflict makes the document non-approvable in the UI, while the approval service remains authoritative and cannot be bypassed through a direct request.

Approval runs in one database transaction, locks the adjustment, selected location/setting context, product stock rows, and relevant serial rows in a deterministic order, then recomputes the plan. A shortage, PKP bucket inconsistency, missing stock row, or invalid serial rolls back the entire document. No partial line approval is allowed.

### Persist actual approval evidence in the existing audit field

Successful approval stores `approved_by`, `approved_at`, and a versioned structure in the existing `adjustments.approval_result` JSON column. It contains location/PKP context, per-product before/movement/after bucket values, serial IDs and condition transitions, transactions, warnings, actor, and timestamp. Pending views use a live preview; approved views use only the immutable stored result for applied values.

No new table is expected. If implementation discovers an environment predating the existing lifecycle migration, that migration must be applied rather than introducing a second audit column.

### Preserve physical totals and serial identity

For each line, approval subtracts the amount from the valid good bucket and adds the identical amount to its corresponding broken bucket. In the current redesigned stock model, `ProductStock::quantity` is the physical total (`quantity_tax + quantity_non_tax + broken_quantity_tax + broken_quantity_non_tax`), not a good/sellable-only figure; the good/sellable total is `quantity_tax + quantity_non_tax`. Breakage holds `ProductStock::quantity` (the physical total) invariant while moving the requested amount from the good bucket to the corresponding broken bucket. Product broken aggregates are recomputed or adjusted consistently; approval must not reduce the product's total physical ownership. Serialized approval changes only `is_broken` from false to true and leaves `location_id`, `tax_id`, and active lifecycle status unchanged.

## Risks / Trade-offs

- [Pending documents do not reserve inventory, so a preview can become stale] → Show drift/conflict feedback and always recompute after locking at approval.
- [Legacy PKP data may exist in the unexpected bucket or carry inconsistent serial tax metadata] → Block the affected line with an explicit Indonesian data-conflict message; never normalize it inside breakage.
- [Sharing scanner logic can accidentally inherit Stock Opname's permissive serial behavior] → Put eligibility behind an explicit breakage policy and cover unknown, cross-location, broken, dispatched, returning, and inactive serial cases with focused tests.
- [Existing approved breakage records lack immutable snapshots] → Continue rendering them through a clearly labeled legacy fallback; only newly approved records promise immutable applied evidence.
- [Derived product aggregates can drift from location rows] → Update them inside the same transaction and assert physical-total invariants in focused approval tests.

## Migration Plan

1. Introduce the breakage planner/approval behavior and approval-result schema version using existing columns.
2. Replace create and edit entry surfaces while accepting existing pending `AdjustedProduct` rows through an adapter.
3. Add the breakage-specific pending/approved review presentation and legacy approved fallback.
4. Deploy with focused automated verification, then perform human browser checks for create, edit, scan, review, conflict, approve, and reject flows.
5. Roll back application behavior by reverting the UI/services; no destructive data rollback is required because existing adjustment fields and rows remain compatible.

## Open Questions

None. Entry, PKP allocation, serial eligibility, approval timing, physical-total handling, and verification scope were confirmed during exploration.
