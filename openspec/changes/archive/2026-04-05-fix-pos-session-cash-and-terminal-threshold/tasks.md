## 1. Backend: Session Index Query

- [x] 1.1 Verify `PosSessionController.index()` eagerly loads `terminal.policy` with the query (should already have `->with(['terminal', 'terminal.policy', ...])`)
- [x] 1.2 Confirm all required session fields are selected: `expected_cash_total`, `opening_float_total`, `terminal_id`, `status`

## 2. Frontend: Session List View - Kas Column

- [x] 2.1 Update "Kas" column display logic in `session/index.blade.php` to always show `expected_cash_total` for all session states (OPEN, CLOSED, CLOSING, FINALIZED)
- [x] 2.2 Remove conditional logic that switches from expected to counted cash based on status
- [x] 2.3 Test rendering: OPEN sessions show expected_cash, CLOSED sessions show expected_cash (not NULL)

## 3. Frontend: Session List View - Threshold Column

- [x] 3.1 Add "Threshold" column header to session index table (between "Pengambilan Kas" and "Metrik" columns)
- [x] 3.2 Add "Threshold" column display logic: show `terminal.policy.cash_threshold` formatted as currency, or "-" if no terminal
- [x] 3.3 Ensure proper text-end alignment and formatting (consistent with other currency columns)

## 4. Frontend: Session List View - Row Highlighting

- [x] 4.1 Add conditional CSS class `table-warning` to `<tr>` when `expected_cash_total > cash_threshold` AND session has a terminal
- [x] 4.2 Ensure highlighting applies to all session states (OPEN, CLOSED, CLOSING, FINALIZED) if threshold exceeded
- [x] 4.3 Add CSS styling for `table-warning` if not already defined by Bootstrap (subtle yellow background)

## 5. Frontend: Finalize Button for Non-Terminal Sessions

- [x] 5.1 Verify finalize button is already disabled for non-terminal sessions (line 135-140 of view)
- [x] 5.2 Add title/tooltip attribute to disabled finalize button: "Finalisasi tidak diperlukan untuk sesi tanpa terminal"
- [x] 5.3 Test: OPEN non-terminal shows disabled finalize with tooltip; CLOSED non-terminal shows no finalize button

## 6. Testing & Verification

- [x] 6.1 Test Kas column consistency: OPEN, CLOSED, CLOSING, FINALIZED all show expected_cash_total (not NULL or 0)
- [x] 6.2 Test threshold display: terminal sessions show threshold value, non-terminal show "-"
- [x] 6.3 Test row highlighting: row has yellow background when expected_cash > threshold
- [x] 6.4 Test no highlight: row normal when expected_cash <= threshold
- [x] 6.5 Test finalize button: disabled for non-terminal with tooltip visible
- [x] 6.6 Test finalize button: enabled for CLOSED terminal sessions
- [x] 6.7 Verify N+1 queries: no extra queries when loading 15 sessions (terminal.policy pre-loaded)
- [x] 6.8 Test with various threshold values (0, positive, NULL)

## 7. Documentation & Cleanup

- [x] 7.1 Verify blade view syntax is correct (no missing `@endif` or similar)
- [x] 7.2 Add inline comments to view explaining Kas column displays expected_cash for all states
- [x] 7.3 Update CLAUDE.md or internal docs if needed (optional, low priority)
