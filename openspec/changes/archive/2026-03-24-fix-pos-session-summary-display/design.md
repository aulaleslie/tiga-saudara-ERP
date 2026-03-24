## Context

The POS session summary page currently receives complete cash event and transaction data from the `PosSessionSummaryService::getSummary()` API but does not process or display the cash reconciliation breakdown. The finalization modal successfully displays this reconciliation (Saldo Awal, Penjualan Kas, Pengambilan Kas), but the main summary view lacks this critical information.

The session summary view template receives:
- `cash_events`: Array of cash events with event_type (OPEN_FLOAT, CASH_SALE_IN, SAFE_DROP_OUT) and direction (IN, OUT)
- `transactions`: Array of posted checkouts with payment methods and amounts
- `expected_cash_total`: Pre-calculated expected cash from the service
- `sales_total`: Sum of all posted checkouts

The view must extract and organize this data to display:
1. Opening float (OPEN_FLOAT events with direction IN)
2. Cash sales (CASH_SALE_IN events with direction IN)
3. Safe drops (SAFE_DROP_OUT events with direction OUT)
4. Non-cash transaction total (user input)
5. Reconciliation formula result

## Goals / Non-Goals

**Goals:**
- Display cash reconciliation breakdown on the session summary page with visual parity to the finalization modal
- Extract opening float, cash sales, and safe drops from cash_events array
- Provide editable input field for non-cash transaction total
- Calculate and display reconciliation result: `opening_float + cash_sales + non_cash - safe_drops = total_sales`
- Show expected cash vs. actual/calculated cash totals
- Ensure reconciliation data flows correctly to finalization modal (consistency)

**Non-Goals:**
- Persist non-cash transaction data to database (input field is for reference only)
- Change the existing finalization modal logic or variance calculation
- Modify cash event creation or the PosSessionSummaryService backend
- Add approval workflows or permission changes
- Create a separate reconciliation verification step

## Decisions

### Decision 1: Data Extraction in View vs. Service
**Decision**: Extract and aggregate cash event data in the view template (Blade), not in the service.

**Rationale**: The service already returns complete cash_events. Extracting in the view keeps backend logic minimal and allows the finalization modal to use the same raw data independently. The view's responsibility is presentation.

**Alternatives Considered**:
- Extract in service and return pre-aggregated values (opening_float, cash_sales, safe_drops) - Would require service modification, but cleaner separation; rejected to avoid backend change scope.
- Use JavaScript to extract on the client - Possible but harder to test and duplicates logic.

### Decision 2: Non-Cash Input Persistence
**Decision**: Non-cash transaction input field is display-only for the summary; users enter the value and it's passed to finalization modal, but not persisted independently.

**Rationale**: Non-cash amounts are context for reconciliation; they flow to finalization where they can be considered during approval. No need for a separate persistence layer.

**Alternatives Considered**:
- Store non-cash amount in session data/API - Extra complexity; user can modify before finalization.
- Accept POST to save non-cash input - Requires new endpoint and database schema; rejected as out of scope.

### Decision 3: Component Structure
**Decision**: Create a new "Perhitungan Kas" card (PHP/Blade component) in the summary view, separate from existing cards but on the same row/section.

**Rationale**: Aligns with existing Bootstrap card layout. Component placement keeps summary readable and organized.

**Alternatives Considered**:
- Embed reconciliation directly in the existing "Ikhtisar Sesi" card - Would clutter the overview card.
- Create a modal overlay - Unnecessary; reconciliation is core to the summary flow.

### Decision 4: Calculation Logic
**Decision**: Use Blade template logic to sum cash events by event_type and direction:
- Opening float = sum of OPEN_FLOAT events with direction IN
- Cash sales = sum of CASH_SALE_IN events with direction IN
- Safe drops = sum of SAFE_DROP_OUT events with direction OUT
- Reconciliation = opening_float + cash_sales + non_cash_input - safe_drops

**Rationale**: Simple aggregation; keeps view-level calculations transparent and testable. Formula matches finalization modal and user expectations.

**Alternatives Considered**:
- Pass pre-calculated values from service - Requires service change.
- Use JavaScript to compute on page load - Harder to test; less accessible if JavaScript fails.

## Risks / Trade-offs

**Risk**: Blade template calculations may be harder to maintain than service-level logic.
→ **Mitigation**: Use clear variable names and comments. Keep aggregation simple and straightforward. If calculations become more complex in future, move to service.

**Risk**: Non-cash input field has no validation or bounds checking in summary view.
→ **Mitigation**: Input is informational only; full validation occurs in finalization flow. Add HTML5 `min="0"` constraint. Finalization modal re-validates before submission.

**Risk**: Cash events array includes all events; filtering by event_type/direction must be accurate.
→ **Mitigation**: Test data aggregation logic with multi-event sessions (multiple opens, pickups). Verify against finalization modal output.

**Risk**: If cash events are added/modified after summary page load, reconciliation won't update without page refresh.
→ **Mitigation**: Summary page is typically viewed after session close; events are frozen. If real-time updates needed in future, add JavaScript polling or WebSocket.

## Migration Plan

1. **Deployment**: Add new reconciliation card to summary.blade.php; no database migration or service changes needed.
2. **Rollback**: Remove the reconciliation card HTML; revert `summary.blade.php` to previous version.
3. **Testing**: Verify card renders with multi-event sessions (open float, sales, pickups). Compare reconciliation values to finalization modal.
