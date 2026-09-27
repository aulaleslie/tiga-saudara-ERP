## Context

Currently, the POS system has print logging infrastructure (`PosReceiptPrintLog`) that tracks receipt prints for completed checkouts only. The table requires a `pos_checkout_id` foreign key, making it unsuitable for draft transactions which have no associated checkout. Users can reprint receipts from the POS page (last transaction) but cannot reprint from the transaction management interface (list/detail views). Print history is not displayed on receipt templates, limiting audit visibility.

Existing infrastructure:
- `pos_receipt_print_logs` table with PRINT/REPRINT tracking
- `PosReceiptService::logPrint()` method for logging
- Routes and controllers for checkout receipts (with reprint already implemented)
- Permission `pos.receipts.reprint` already in use
- `PosTransactionController::receipt()` method without logging

## Goals / Non-Goals

**Goals:**
- Enable users with `pos.receipts.reprint` permission to reprint any transaction (DRAFT, LOADED, COMPLETED, CANCELLED)
- Log all transaction receipt prints (initial + reprints) with user ID and timestamp
- Display print history on receipts: "Printed X times. Last printed by [name] at [time]"
- Make print history visible in printed receipt for audit trail
- Add reprint buttons to transaction list (action column) and transaction detail (header)
- Reuse existing permission and logging infrastructure

**Non-Goals:**
- Create separate permission for transaction reprints
- Implement print history for checkouts beyond what already exists
- Add filtering/search by print logs in transaction list
- Send notifications or alerts for reprints
- Restrict users from reprinting their own transactions

## Decisions

### 1. Database Schema: Nullable pos_checkout_id

**Decision**: Make `pos_checkout_id` nullable in `pos_receipt_print_logs` table instead of creating a separate transaction print log table.

**Rationale**: 
- Single source of truth for all print logs
- Minimal schema change (one nullable foreign key)
- Leverages existing logging infrastructure

**Alternatives considered**:
- Create separate `pos_transaction_receipt_print_logs` table → More code duplication, harder to query across both types
- Add `pos_transaction_id` column → Would require indexes on both FK columns, complicates queries

### 2. Print History Retrieval and Display

**Decision**: Load print logs in controller, calculate summary (count + last printer) once per request, pass to view.

**Rationale**:
- Efficient single query per receipt view
- Summary calculated once, not on each render
- Easy to test and maintain

**Implementation flow**:
```
Controller: Load print logs with eager loading (user relationship)
Controller: Calculate summary: count and last_printer_info
View: Display summary string in header section of receipt
View: Display full log details (if needed for audit)
```

### 3. Reprint Endpoint for Transactions

**Decision**: Create `/pos/transactions/{transaction}/receipt/reprint` POST endpoint (RESTful pattern).

**Rationale**:
- Mirrors existing checkout reprint route pattern
- POST is semantically correct for a state-changing action (logging)
- Easy to add permission middleware

**Implementation**:
- Method: `PosTransactionController::receiptReprint()`
- Protect with `pos.receipts.reprint` permission
- Log print as REPRINT type
- Return receipt view with print logs

### 4. Reprint Button Placement and Visibility

**Decision**: 
- Transaction list: Add reprint button in action column (after "Detail" button)
- Transaction detail: Add reprint button in card header (next to "Load" and "Cancel" buttons)
- Show button for all statuses except CANCELLED (user choice: "all can be reprint")
- Hide button if user lacks `pos.receipts.reprint` permission

**Rationale**:
- Consistent placement in existing action areas
- Non-intrusive addition to existing layouts
- Permission-based visibility already patterns in codebase

### 5. Print History Display Timing

**Decision**: Print history displays in receipt template (visible both on-screen and when printing).

**Rationale**:
- Audit trail printed with physical receipt
- No separate info section needed
- Minimal template changes (add one section before/after business header)

**Display format**:
```
[Header section with business info...]
[DIVIDER]
Printed 4 times. Last printed by John Doe at 2026-04-05 14:30:45
[DIVIDER]
[Receipt items...]
```

### 6. Print Logging for Initial View

**Decision**: Log print type as "PRINT" when viewing receipt for first time, "REPRINT" for subsequent views via dedicated endpoint.

**Rationale**:
- Distinguishes initial print from reprints
- Current checkout behavior already does this
- Maintains consistency with existing logs

**Implementation**:
- `receipt()` method logs as PRINT
- `receiptReprint()` method logs as REPRINT

## Risks / Trade-offs

- **Risk**: Making `pos_checkout_id` nullable breaks strict FK integrity
  - **Mitigation**: Add index on (pos_checkout_id, pos_transaction_id) for efficient queries; document that one of these will always be non-null

- **Risk**: Print history might become very long if transaction is reprinted many times
  - **Mitigation**: Not in scope for v1; can implement pagination/summary in future

- **Risk**: Users might reprint excessively by accident
  - **Mitigation**: Button is optional and permission-gated; no warning dialog needed per requirements

## Migration Plan

1. Create migration to make `pos_checkout_id` nullable
2. Add new columns if needed to `pos_receipt_print_logs` to track transaction reprints
3. Deploy code changes (route, controller, views)
4. No data migration needed (existing logs unaffected)
5. No rollback complexity (purely additive feature)

## Open Questions

- Should we add a `pos_transaction_id` column to `pos_receipt_print_logs` for faster lookups, or query through the associated checkout's transaction?
- Should print history summary be loaded via eager loading or separate query? (Recommend eager loading for simplicity)
