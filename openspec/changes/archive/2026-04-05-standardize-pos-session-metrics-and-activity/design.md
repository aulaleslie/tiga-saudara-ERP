## Context

Currently, the POS sessions list (`/pos/sessions`) displays transaction metrics and activity timestamps with logic that varies by session status. Terminal sessions track cash through `PosSessionCashEvent` (CASH_SALE_IN events represent cashier checkouts), while non-terminal sessions (floor staff workflow) create `PosTransaction` drafts but don't generate cash events until checkout completion. The view conditionally displays different data based on status, creating inconsistency.

The system has two distinct workflows:
- **Terminal sessions**: Formal cash accounting via cash events. Only cashier can complete transactions.
- **Non-terminal sessions**: Floor staff create transaction drafts. Cashier loads and completes them later. Total draft volume represents transaction creation activity.

The metrics should show complete picture for both workflows without status-based confusion.

## Goals / Non-Goals

**Goals:**
- Display consistent "Metrik" and "Aktivitas Terakhir" columns across all session statuses (OPEN, CLOSED, CLOSING, FINALIZED)
- For terminal sessions: show cash completion metrics (CASH_SALE_IN event counts)
- For non-terminal sessions: show transaction creation volume (all PosTransaction records, regardless of status)
- For activity timestamps: always show the most recent event (cash event for terminals, transaction creation for non-terminals)
- Remove status-dependent conditional logic that hides data for CLOSED sessions

**Non-Goals:**
- Change cash reconciliation logic or variance calculations
- Modify transaction lifecycle or status transitions
- Add new filtering or segmentation capabilities
- Change threshold display or row highlighting logic

## Decisions

### Decision 1: Data Source Selection Based on Terminal Presence
**Choice**: Conditionally query and display data based on `session.terminal_id` presence
- For `terminal_id IS NOT NULL`: Use PosSessionCashEvent counts and timestamps
- For `terminal_id IS NULL`: Use PosTransaction counts and timestamps

**Rationale**: The two workflows are fundamentally different. Cash events only exist for cashier completions. Transaction drafts exist across both roles. Merging them would lose information (non-terminal sessions would show zero metrics if we only counted cash events).

**Alternatives Considered**:
1. Always count both and display as "X cash / Y drafts" — adds column complexity, harder to read
2. Always show only cash events — loses visibility into floor staff transaction creation
3. Add new separate columns for each type — increases table width, violates consistency goal

### Decision 2: Metrik Query Implementation
**Choice**: Add two new withCount aggregations in controller query
```
withCount([
  'cashEvents as transaction_count' => function ($query) {
    return $query->where('event_type', PosSessionCashEvent::EVENT_CASH_SALE_IN);
  },
  'transactions as draft_transaction_count' => function ($query) {
    return $query->where('source_pos_session_id', ...); // all statuses
  }
])
```

**Rationale**: Both counts are pre-aggregated in a single query round-trip. The view can select the appropriate count based on terminal presence with zero additional logic.

**Alternatives Considered**:
1. Calculate counts in view loop — N+1 queries
2. Join within query and conditionally select — more complex SQL, same end result

### Decision 3: Activity Timestamp Implementation
**Choice**: Add two new withMax aggregations for last activity timestamps
```
withMax([
  'cashEvents as last_cash_activity' => 'occurred_at',
  'transactions as last_transaction_created' => 'created_at'
])
```

**Rationale**: Consistent with Metrik approach. Pre-aggregated timestamps avoid view logic. Cash events have `occurred_at` (formal booking time), transactions have `created_at` (draft creation time).

### Decision 4: Timestamp Formatting
**Choice**: Format as HH:mm for OPEN sessions, full datetime for CLOSED
- OPEN: "14:32" (within same day context)
- CLOSED: "05/04/2026 14:32" (full context for historical reference)

**Rationale**: OPEN sessions are current, short format is readable. CLOSED sessions are historical, full date provides context.

### Decision 5: View Column Logic Simplification
**Choice**: Remove status-based conditionals, replace with terminal-based conditionals
```blade
@if($session->terminal)
  {{ $session->transaction_count }}
@else
  {{ $session->draft_transaction_count }}
@endif
```

**Rationale**: Clearer intent. Terminal presence is the deciding factor, not session status.

## Risks / Trade-offs

**Risk**: Counting all PosTransaction statuses for non-terminal may include CANCELLED transactions
- **Mitigation**: This is intentional — shows total transaction creation activity. Cancelled transactions still represent operator work. If filtering by status becomes necessary later, it can be added without breaking the display logic.

**Risk**: Cash events and transaction created_at may have different time zones or precision
- **Mitigation**: Both use same timestamp column type and Laravel's timezone handling. No special handling needed.

**Risk**: Non-terminal sessions may have hundreds of draft transactions if floor staff creates many drafts
- **Mitigation**: This is accurate representation of activity volume. If display becomes problematic, pagination or summary views can be added separately.

## Migration Plan

**Deploy Steps**:
1. Deploy backend changes (controller query updates) — backward compatible, just adds new attributes
2. Deploy frontend changes (view logic updates) — reads new attributes, removes old conditionals
3. No database migrations needed
4. No data transformation needed

**Rollback Strategy**:
- Revert view to show status-based conditionals
- Revert controller to remove new withCount/withMax
- Old column behavior restored immediately (no data cleanup)

## Open Questions

None — design is complete and ready for implementation.
