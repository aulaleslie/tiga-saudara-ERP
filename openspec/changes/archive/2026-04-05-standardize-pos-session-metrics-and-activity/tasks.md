## 1. Backend: Query Aggregations

- [x] 1.1 Update `PosSessionController.index()` to add `withCount(['transactions as draft_transaction_count'])` for all PosTransaction records with source_pos_session_id matching the session
- [x] 1.2 Update the withCount to add `withMax(['transactions as last_transaction_created' => 'created_at'])` to get most recent transaction creation timestamp
- [x] 1.3 Update the withMax for cashEvents to add `withMax(['cashEvents as last_cash_activity' => 'occurred_at'])` to get most recent cash event timestamp
- [x] 1.4 Verify all eager loads are in single query (use `explain()` or query profiler to confirm no N+1)
- [x] 1.5 Test controller returns new attributes: `$session->draft_transaction_count`, `$session->last_transaction_created`, `$session->last_cash_activity`

## 2. Frontend: Metrik Column Logic

- [x] 2.1 Open `Modules/Pos/Resources/views/session/index.blade.php`, locate Metrik column (around line 113-123)
- [x] 2.2 Replace the status-based conditional logic with terminal-based logic:
  - `if($session->terminal)` → display `$session->transaction_count`
  - `else` → display `$session->draft_transaction_count`
- [x] 2.3 Remove the `@if($session->status === 'OPEN')` and `@elseif($session->status === 'CLOSED'...)` conditions entirely
- [x] 2.4 Add comment explaining: "Metrik shows CASH_SALE_IN count for terminal sessions, total PosTransaction count for non-terminal"
- [x] 2.5 Test: Load sessions list, verify both terminal and non-terminal sessions show counts for OPEN, CLOSED, CLOSING, FINALIZED statuses

## 3. Frontend: Aktivitas Terakhir Column Logic

- [x] 3.1 Open Metrik column display (around line 125-132)
- [x] 3.2 Replace status-based conditional with terminal-based logic:
  - `if($session->terminal)` → use `$session->last_cash_activity`
  - `else` → use `$session->last_transaction_created`
- [x] 3.3 For both branches, format timestamp based on session status:
  - `if($session->status === 'OPEN')` → format as `H:i` (e.g., "14:32")
  - `else` → format as `d/m/Y H:i` (e.g., "05/04/2026 14:32")
  - If timestamp is null → display "-"
- [x] 3.4 Remove the old `@if($session->status === 'OPEN')` / `@else` logic that only showed times for OPEN
- [x] 3.5 Add comment explaining: "Aktivitas Terakhir shows last cash event for terminals, last transaction creation for non-terminals"
- [x] 3.6 Test: Verify OPEN and CLOSED sessions both show timestamps in Aktivitas Terakhir, formatted correctly

## 4. Integration Testing

- [x] 4.1 Create test session with terminal that has 5+ CASH_SALE_IN events → verify Metrik shows count, Aktivitas Terakhir shows most recent timestamp
- [x] 4.2 Create test session without terminal that has 3+ PosTransaction drafts → verify Metrik shows count, Aktivitas Terakhir shows most recent created_at
- [x] 4.3 Test OPEN session (both terminal and non-terminal) → timestamps formatted as H:i
- [x] 4.4 Test CLOSED session (both terminal and non-terminal) → timestamps formatted as d/m/Y H:i
- [x] 4.5 Test session with no activity (no cash events, no transactions) → Aktivitas Terakhir shows "-" for both types
- [x] 4.6 Test filter switching (Semua → Aktif → Selesai) → verify column headers remain aligned, data updates correctly
- [x] 4.7 Test pagination (load page 2 of sessions) → verify all metrics display correctly
- [x] 4.8 Run query profiler → confirm no N+1 queries on sessions list load (max 2-3 total queries)

## 5. Code Review & Cleanup

- [x] 5.1 Review `PosSessionController.index()` for any unused imports or variables
- [x] 5.2 Review blade view for any debug output or commented code
- [x] 5.3 Verify inline comments are clear and accurate
- [x] 5.4 Check blade syntax: no missing `@endif`, `@endforelse`, etc.
- [x] 5.5 Verify CSS classes and Bootstrap styling still apply correctly to Metrik and Aktivitas Terakhir cells
