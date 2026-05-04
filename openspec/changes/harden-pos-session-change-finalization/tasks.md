## 1. Backend Summary Contract

- [x] 1.1 Extend `PosSessionSummaryService` terminal-session output with `cash_tendered_total`, `change_total`, and `net_cash_sales_total`.
- [x] 1.2 Compute cash tendered from posted checkout payment rows joined to cash payment methods.
- [x] 1.3 Compute change total from posted checkout `change_total` values scoped to the session.
- [x] 1.4 Preserve existing non-terminal session response behavior without adding cash-only fields.
- [x] 1.5 Ensure `expected_cash_total` in summary remains sourced from `PosSessionExpectedCashCalculator`.

## 2. Finalization Modal

- [x] 2.1 Update the finalization modal markup to show net cash sales, cash tendered, change returned, safe drops, and backend expected cash.
- [x] 2.2 Update `public/js/pos-session-handlers.js` to populate finalization values from summary JSON fields.
- [x] 2.3 Make finalization variance calculation use numeric `session.expected_cash_total` rather than frontend-derived expected cash.
- [x] 2.4 Avoid parsing formatted currency text for variance threshold or expected cash where numeric JSON values are available.

## 3. Session Detail Timeline

- [x] 3.1 Add a `CHANGE_OUT` / `Kembalian` filter button to the session summary cash events timeline.
- [x] 3.2 Ensure `CHANGE_OUT` events remain displayed as OUT movements with negative visual treatment.
- [x] 3.3 Verify existing `Semua`, `Penjualan`, `Pickup`, and `Modal` filters still work.

## 4. Tests

- [x] 4.1 Add or update summary JSON tests for a Rp990,000 cash sale paid with Rp1,000,000 and Rp10,000 change.
- [x] 4.2 Assert summary JSON reports `cash_tendered_total`, `change_total`, `net_cash_sales_total`, and backend `expected_cash_total` correctly.
- [x] 4.3 Add or update finalization-related tests to confirm variance uses backend expected cash for the same overpayment case.
- [x] 4.4 Add or update view/UI assertions for the `CHANGE_OUT` / `Kembalian` filter.

## 5. Verification

- [x] 5.1 Run focused POS session summary/finalization tests.
- [x] 5.2 Run focused POS checkout change tests to ensure existing `CHANGE_OUT` ledger behavior is preserved.
- [ ] 5.3 Manually verify `/pos/sessions/{session}/summary` and `/pos/sessions` finalization flow with a cash-overpayment checkout.
