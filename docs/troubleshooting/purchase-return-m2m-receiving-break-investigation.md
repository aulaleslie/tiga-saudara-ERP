# Purchase Return Create: Cross-Purchase Serial Validation Investigation (2026-02-20)

## Context

Investigated issue on `http://localhost:8000/purchase-returns/create`:

- There are 2 purchases in current DB:
  - old purchase (`id=1`, `reference=TNC-BL-2026-02-00001`)
  - new purchase (`id=2`, `reference=TNC-BL-2026-02-00002`)
- Serial `A` was returned from old purchase, then received again in new purchase.
- User tries to create a new return with both:
  - serial `B` (still from old purchase)
  - serial `A` (now current source is new purchase)
- Initial expectation raised in ticket: duplicate guard should be `product + location` only, not `purchase`.

## Data Snapshot (Tinker)

### Purchases

`php artisan tinker --execute='...'` returned:

- `{"id":1,"reference":"TNC-BL-2026-02-00001","status":"RECEIVED"}`
- `{"id":2,"reference":"TNC-BL-2026-02-00002","status":"RECEIVED"}`

### Existing Purchase Returns

- Return `TNC-PRRN-2026-02-00001` detail: `po_id=1`, `serial_number_ids=[1,2]`
- Return `TNC-PRRN-2026-02-00002` detail: `po_id=1`, `serial_number_ids=[1]`

### Serial Mapping Relevant to Scenario

- Serial ID `1` (`202602200001`) behaves as serial `A`:
  - historical received purchase IDs: `[1,2]`
  - `resolveCurrentPurchaseId() = 2` (new purchase)
- Serial ID `2` (`202602200002`) behaves as serial `B`:
  - historical received purchase IDs: `[1]`
  - `resolveCurrentPurchaseId() = 1` (old purchase)

## Reproduction Results

### 1. Loader blocks adding second serial from different purchase in same row

Using Livewire test harness in tinker:

- Existing row serial: serial `B` (`purchase_order_id=1`)
- Add serial `A` (`resolveCurrentPurchaseId()=2`)
- Result:
  - `ERROR=Nomor seri berasal dari pembelian yang berbeda, tambahkan baris baru dan scan ulang nomor seri.`

Source:

- `app/Livewire/PurchaseReturn/PurchaseOrderSerialNumberLoader.php:97`
- `app/Livewire/PurchaseReturn/PurchaseOrderSerialNumberLoader.php:107`

### 2. Submit-time validator also blocks mixed-purchase serials in one row

Validator reproduction (with production env lazy-loading relaxed) for one row with serial `B` + serial `A`:

- Result:
  - `rows.0.serial_numbers => Nomor seri '202602200001' berasal dari pembelian yang berbeda.`

Source:

- `app/Livewire/PurchaseReturn/Concerns/ValidatesPurchaseReturnForm.php:131`

### 3. "Add new row" suggestion conflicts with row-combination guard

If serials are split into 2 rows (same product and location, different purchase IDs), submit validator fails:

- Result:
  - `rows.1.product_id => Kombinasi produk dan lokasi ini sudah ada.`

Source:

- `app/Livewire/PurchaseReturn/Concerns/ValidatesPurchaseReturnForm.php:79`

This creates a dead-end:

- Same row is blocked by purchase mismatch.
- Separate row is blocked by duplicate product+location.

## Code-Level Root Cause

Current create flow still enforces **single purchase context per row**:

1. First scanned serial writes one `purchase_order_id` for the row.
   - `app/Livewire/PurchaseReturn/PurchaseReturnTable.php:182`
2. Loader requires all subsequent serials in that row to match first serial's purchase.
   - `app/Livewire/PurchaseReturn/PurchaseOrderSerialNumberLoader.php:107`
3. Submit validator re-checks each serial against row `purchase_order_id`.
   - `app/Livewire/PurchaseReturn/Concerns/ValidatesPurchaseReturnForm.php:131`

At persistence time, each detail stores a **single** `po_id`:

- `app/Livewire/PurchaseReturn/PurchaseReturnCreateForm.php:129`
- column: `purchase_return_details.po_id`

## Important Downstream Constraint

Settlement logic frequently uses detail-level `po_id` as source/origin purchase context:

- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php:314`
- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php:937`

Backfill command already treats multi-purchase serial sets in one detail as ambiguous:

- `Modules/PurchasesReturn/Console/BackfillPurchaseReturnDetailPoId.php:85`

This means relaxing create validation alone is not sufficient unless detail-level purchase context is redesigned for multi-origin serials.

## Findings Summary

- The reported error is validly reproducible.
- The codebase currently enforces purchase consistency per row and effectively prevents mixed-purchase serial returns for same product+location.
- Current behavior conflicts with the initial ticket expectation and creates a dead-end for multi-purchase serial selection.
- There is also a structural dependency on single `po_id` per detail in settlement flows, so this is not only a UI/validator issue.

## Design Consideration Update (Single-Purchase Per Row)

### Question

Can we keep **single purchase per row/detail** and still support:

- old purchase serial `A` shown red
- new purchase serial `A` shown blue
- creating one purchase-return document containing serials from multiple purchases

### Short Answer

Yes. This is the safest direction and should preserve the existing red/blue behavior, as long as multi-purchase is modeled as **multiple rows/details**, each with its own `po_id`.

### Why This Is Safer

1. Current persistence and settlement design is detail-centric with one `po_id`.
   - `purchase_return_details.po_id` is singular by schema and usage.
   - Settlement logic depends on this detail purchase context.
2. Returned/active visual behavior (old red/new blue) depends on serial history + purchase context resolution.
   - Keeping `po_id` correct per detail avoids ambiguity.
3. Mixing purchase sources inside one detail would conflict with current assumptions and can create ambiguous downstream behavior.

### What Must Change (Minimal, Compatible)

To support multi-purchase return creation without redesigning settlement:

1. Keep serial purchase-source enforcement **inside a row**.
   - Loader and validator purchase-mismatch checks stay.
2. Relax duplicate-row guard for serial products.
   - Current guard blocks same `product + location` on another row.
   - New rule should allow same `product + location` when `purchase_order_id` differs.
   - Effective uniqueness for serial rows: `product + location + purchase_order_id`.
3. Show purchase source in UI per row.
   - Because location/product may be identical across rows, purchase reference must be visible and locked from first scanned serial.
4. Keep one `po_id` per persisted detail.
   - This preserves settlement and historical compatibility.

### Expected Outcome with This Approach

- User can create one purchase return with:
  - Row 1: product X, location L, purchase old, serial `B`
  - Row 2: product X, location L, purchase new, serial `A`
- Existing old-red/new-blue behavior remains intact because purchase context is still explicit per detail.
- No immediate schema redesign is required.

### Residual Risk / Note

Without displaying purchase reference clearly in the table, users can misread duplicated product/location rows. UI visibility of purchase source is required for operational clarity.

## Alternative Rethink: Purchase Context on Serial Row (Not Detail)

Status: `REJECTED` for this implementation cycle.

### Your Proposed Direction

Instead of storing purchase source at `purchase_return_details.po_id`, store purchase source per selected serial row.

### Feasibility

Feasible, and conceptually cleaner for serial-tracked returns.

It aligns with the real-world rule that each serial has its own source lineage and can differ even when product and location are the same.

### Why It Can Be Better

1. Removes forced single-purchase assumption at detail level for serial lines.
2. Supports mixed purchase-source serials in one product/location line naturally.
3. Reduces ambiguity for settlement source purchase resolution.

### Why It Is Not a Small Change

Current code depends on detail-level purchase context in multiple places:

1. Create/Edit persistence writes one `po_id` per detail.
2. Settlement origin resolution prioritizes `PurchaseReturnDetail.purchase` (detail `po_id`) as "most reliable".
3. Settlement stock/payment effects fallback to detail `po_id` in key branches.
4. Backfill tooling and assumptions explicitly treat multi-purchase serials under one detail as ambiguous.

### Required Design Changes (If We Choose This Path)

1. Add serial-line source mapping storage.
   - Recommended: new table (example) `purchase_return_detail_serial_sources` with:
     - `purchase_return_detail_id`
     - `product_serial_number_id`
     - `source_purchase_id`
   - Keep unique key on (`purchase_return_detail_id`, `product_serial_number_id`).
2. Keep `po_id` for non-serial details only.
   - For serial-required details, `po_id` can be nullable/legacy fallback.
3. Update create/edit flow.
   - Persist source purchase per selected serial at submit time.
   - UI must show source purchase per serial chip/row.
4. Update settlement source resolution.
   - Primary source becomes serial-line mapping.
   - Detail `po_id` becomes fallback only.
5. Update approval/dispatch/settlement validations and tests to use serial-line source context.
6. Provide data backfill for historical records.
   - Existing serial details can be seeded from `po_id` where unambiguous.
   - Ambiguous historical cases require deterministic fallback rule.

### Important Behavioral Note

If source purchase is derived dynamically from `resolveCurrentPurchaseId()` only at settlement time, results can drift when serial lineage changes after draft creation.  
To avoid this drift, source purchase should be snapshotted at return creation per serial line.

### Decision

Current implementation direction is to keep purchase validation and source context at purchase-return item/detail level, and not proceed with per-serial purchase-source storage.

## Hardening Applied (Chosen Path)

Implementation has been aligned to item/detail-level purchase validation:

1. Purchase validation hardened at row/detail level.
   - Validate selected `purchase_order_id` exists.
   - Validate purchase belongs to selected supplier.
   - Validate product exists on selected purchase.
2. Serial row uniqueness updated to support multi-purchase in one return document.
   - For serial rows, uniqueness key is now effectively `product + location + purchase_order_id`.
   - For non-serial rows, uniqueness remains `product + location`.
3. UI now shows purchase reference on serial rows to avoid ambiguity for same product/location rows.
4. Settlement serial origin auto-select path no longer falls back to serial lineage when detail purchase context is missing.
   - Origin purchase for serial settlement now relies on purchase-return detail context (`po_id`) only.

### Verification

Targeted tests were added/updated and passed:

- `tests/Feature/PurchaseReturnSerialUniquenessTest.php`
  - includes same product/location with different purchase rows (allowed)
  - includes supplier mismatch purchase rejection
- `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnSerialSettlementAutoSelectTest.php`
  - includes no auto-select when serial detail has no `po_id`

## Additional Investigation: Mixed Settlement Causes Old Purchase Badge Drift (2026-02-20)

### Scenario Verified via Tinker

Dataset checked in current local DB:

- Purchase A (`id=1`, `reference=TNC-BL-2026-02-00001`)
  - serial `202602200001`
  - serial `202602200002`
- Purchase B (`id=2`, `reference=TNC-BL-2026-02-00002`)
  - serial `202602200001` (reused)

Flow reproduced:

1. Serial `202602200001` was previously returned from old purchase, then received in new purchase.
2. Create another return (`TNC-PRRN-2026-02-00003`) with 2 rows:
   - Row purchase B (`po_id=2`), serial `202602200001`, method `PRODUCT_REPAIR`.
   - Row purchase A (`po_id=1`), serial `202602200002`, method `MODIFY_PURCHASE`.
3. Settlement result for repair row uses replacement serial `202602200002` (`replacement_serial_number_id=2` on settlement item id `5`).

Current observed behavior:

- New purchase view: `202602200001` red, `202602200002` blue (as expected).
- Old purchase view: `202602200002` remains blue (unexpected; expected red).

### Tinker Evidence Summary

- `product_serial_numbers`:
  - serial `202602200001` => `status=RETURNED`, `purchase_return_id=3`
  - serial `202602200002` => `status=ACTIVE`, `purchase_return_id=null`, `received_note_detail_id=2`
- Histories for `202602200002`:
  - has `RECEIVED` only for old purchase detail (`reference_id=1`)
  - has later `REPAIR_RECEIVED` events
  - does **not** have a later `RECEIVED` event that moves lineage context
- Resolver result:
  - old purchase returned serials => only `202602200001`
  - new purchase returned serials => only `202602200001`
  - serial `202602200002` is excluded from old purchase returned set

### Root Cause

Two rules interact and cause the mismatch:

1. `ReturnedSerialNumberResolver` rejects a returned candidate when serial is active and current purchase matches the viewed purchase.
   - `Modules/Purchase/Services/ReturnedSerialNumberResolver.php:102`
2. `resolveCurrentPurchaseId()` prioritizes latest `EVENT_RECEIVED` history before pivot/FK fallback.
   - `Modules/Product/Entities/ProductSerialNumber.php:118`
3. In `PRODUCT_REPAIR` replacement path for an existing replacement record, code writes `EVENT_REPAIR_RECEIVED` but not `EVENT_RECEIVED`.
   - `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php:384`

Because serial `202602200002` lacks a newer `EVENT_RECEIVED`, `resolveCurrentPurchaseId()` still resolves to old purchase (`id=1`), and resolver filter removes it from old purchase returned list.

### Fix Approach (No Schema Migration)

Yes, this is fixable without migration.

Recommended approach:

1. Keep current schema (`po_id` at purchase-return detail level) unchanged.
2. In `PRODUCT_REPAIR` replacement branch (existing replacement serial case), after binding replacement serial to source `ReceivedNoteDetail`, also record `SerialNumberHistory::EVENT_RECEIVED` with that `ReceivedNoteDetail` as reference.
3. Keep existing `EVENT_REPAIR_RECEIVED` for settlement semantics.

Why this is preferred:

- Aligns lineage resolver input with actual source reassignment event.
- Preserves current resolver and UI behavior without widening condition hacks.
- Avoids risky global behavior changes in `resolveCurrentPurchaseId()` ordering.

### Alternative (Lower Priority)

Change `resolveCurrentPurchaseId()` priority to prefer latest M:N pivot or legacy FK over history.

Not preferred because:

- It can shift behavior broadly for historical records where history is currently authoritative.
- Harder to reason about regressions than writing the missing `EVENT_RECEIVED` at the point of state transition.

### Regression Test Recommendation

Add one feature test for this exact sequence:

1. old purchase has `A` and `B`
2. new purchase has `A`
3. return old `A`, receive in new purchase
4. new return: old `B` as `MODIFY_PURCHASE`, new `A` as `PRODUCT_REPAIR` with replacement `B`
5. assert:
   - old purchase marks `B` returned/red
   - new purchase keeps `B` active/blue
