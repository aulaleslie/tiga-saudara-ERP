## Context

POS serial operations (`availableSerialsForProduct` and `appendSerial` in `PosCartService`) determine availability by checking two fields on `ProductSerialNumber`: `status = 'ACTIVE'` and `dispatch_detail_id IS NULL`. Sale dispatches only update these fields upon approval (`adjustStockForDispatchDetail`). During the PENDING window, serials are referenced only in `DispatchDetail.serial_numbers` (a JSON column), leaving the `ProductSerialNumber` record untouched.

The dispatch creation validator (`SaleController::storeDispatch`) already queries PENDING dispatch details via JSON LIKE to prevent double-dispatching. The same pattern can be reused in POS.

## Goals / Non-Goals

**Goals:**
- Prevent POS from listing or accepting serials that are in any PENDING dispatch
- Provide a clear error message when a blocked serial is attempted
- Guard the finalize pre-check against serials that enter PENDING state between cart assignment and checkout

**Non-Goals:**
- Introducing a new `RESERVED` status on `ProductSerialNumber` (too much cross-module impact for this fix)
- Changing the dispatch approval flow or when `dispatch_detail_id` is set
- Retroactively cleaning up any past double-sale data

## Decisions

### D1: Query-based guard using DispatchDetail JSON LIKE (Option A)

**Decision**: Add a subquery/helper that checks `DispatchDetail.serial_numbers LIKE '%"<serial>"%'` where the parent `Dispatch.status = 'PENDING'`. Use this in both `availableSerialsForProduct` (search filter) and `appendSerial` (append validation).

**Rationale**: This matches the existing pattern in `SaleController::storeDispatch` validation (line ~609). No schema changes needed, no new status values, no cleanup on dispatch reject.

**Alternatives considered**:
- *Option B (RESERVED status)*: Would require a new status constant, updates in `storeDispatch`, rollback logic in `rejectDispatch`, and auditing all code that checks `status === 'ACTIVE'`. Higher risk and scope.
- *Option C (set dispatch_detail_id early)*: Would require clearing it on reject and changing the approval flow. The FK semantics would be misleading since dispatch isn't approved yet.

### D2: Extract a shared helper for the pending-dispatch serial check

**Decision**: Create a static helper method (e.g., `PendingDispatchSerialGuard::isReserved(string $serialNumber): bool`) or a scope/query method that both POS and the dispatch validator can reuse. This avoids duplicating the JSON LIKE pattern.

**Rationale**: The dispatch controller already has this logic inline. Extracting it reduces duplication and ensures consistency if the dispatch data model changes later.

### D3: Batch-filter for serial search, single-check for append

**Decision**: For `availableSerialsForProduct`, collect all serials in PENDING dispatches for the given product as a set, then exclude them. For `appendSerial`, do a single existence check for the specific serial.

**Rationale**: The search returns up to 10 results and could match many candidates. A batch exclusion (one query to get all pending serials for a product, then `whereNotIn`) is more efficient than N individual LIKE checks. The append path only validates one serial, so a single LIKE check is fine.

## Risks / Trade-offs

- **[Risk] JSON LIKE on `serial_numbers` column is not indexed** → Mitigated by the small number of PENDING dispatches at any time (typically single digits). If this becomes a performance concern, a `pending_serial_reservations` denormalized table could be introduced later.
- **[Risk] Race condition between POS check and dispatch creation** → Extremely narrow window. The finalize pre-check provides a secondary guard at checkout time. Acceptable for now.
- **[Risk] Serialized JSON format varies** → The dispatch code uses `json_encode` which produces `["SN-001","SN-002"]`. The LIKE pattern `%"SN-001"%` is safe for this format. Double-encoded or whitespace-padded JSON would break it, but the codebase consistently uses standard `json_encode`.
