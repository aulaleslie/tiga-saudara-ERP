# Implementation Tasks: Multi-Stage Sequential Payments

## 1. Backend Setup & New Endpoint

- [x] 1.1 Create `POST /pos/sell/checkout/stage-payment` endpoint in POS controller
- [x] 1.2 Implement stage payment business logic (validate amount, commit payment to DB, calculate remainder)
- [x] 1.3 Add session state tracking: initialize and update payment chain in session on each stage commit
- [x] 1.4 Implement idempotency key validation (prevent duplicate submissions)
- [x] 1.5 Create database migration for payment stage tracking if needed (order, committed_at timestamp)
- [x] 1.6 Add error handling: distinct responses for CASH failures vs. EDC reference validation errors

## 2. EDC Reference Validation & Capture

- [x] 2.1 Add `is_cash` field to payment methods (or verify it exists)
- [x] 2.2 Implement EDC reference format validation: non-empty, alphanumeric, max 20 chars
- [x] 2.3 Create database column/field to store EDC reference with payment record
- [x] 2.4 Update payment commit logic to accept and store reference for non-cash methods
- [x] 2.5 Add server-side validation endpoint for EDC reference format (or include in stage-payment)

## 3. Frontend State Machine & Modal Redesign

- [x] 3.1 Extract payment logic from current modal into a state machine (states: idle, selecting_method, validating_reference, processing, complete)
- [x] 3.2 Refactor HTML: simplify payment modal layout—remove left cart summary, focus on remainder + method selection + amount input
- [x] 3.3 Create payment chain UI component (list of committed payments with method, amount, and reference if applicable)
- [x] 3.4 Implement modal lock state: disable inputs, show spinner, hide close button during processing
- [x] 3.5 Add EDC reference input field (conditional—only show for non-cash methods)
- [x] 3.6 Implement client-side EDC reference format validation (real-time feedback)

## 4. Payment Staging Loop & Remainder Logic

- [x] 4.1 Implement JavaScript function to submit single payment stage via `/pos/sell/checkout/stage-payment`
- [x] 4.2 Create remainder recalculation logic: new_remainder = old_remainder - committed_amount
- [x] 4.3 Implement post-submit flow: if remainder > 0, reset form for next stage; if remainder = 0, trigger final flow
- [x] 4.4 Handle overpayment scenario: accept overpayment, calculate change, trigger final flow with change amount
- [x] 4.5 Implement error retry logic: on payment failure, show error and allow user to retry with same or different method

## 5. Reload Recovery & Session Persistence

- [x] 5.1 Add session state check on page load (window.onload or DOMContentLoaded)
- [x] 5.2 Implement automatic modal open on reload if payment chain is in-progress
- [x] 5.3 Reconstruct payment chain UI from session state on reload (display committed payments + remainder)
- [x] 5.4 Implement session timeout handling: clear state, show warning message if session expired
- [x] 5.5 Test reload recovery at various stages (after 1st, 2nd, 3rd payment)

## 6. Receipt Print & Gratitude Flow

- [x] 6.1 Keep existing `printReceipt()` function; trigger it after final payment succeeds
- [x] 6.2 Implement [Print Receipt] button in final summary (before printing auto-triggers if desired)
- [x] 6.3 Open receipt in new tab using `window.open()`
- [x] 6.4 Create gratitude modal: "Jangan lupa ucapkan terima kasih!" with change amount displayed (if overpaid)
- [x] 6.5 Implement final flow: close payment modal → show gratitude modal → OK button returns to main POS

## 7. Integration with Existing Checkout Flow

- [x] 7.1 Update "Pilih Pembayaran" button behavior to open new staged modal (not current modal)
- [x] 7.2 Ensure cart summary still shows on main POS (unchanged)
- [x] 7.3 Verify transaction finalize endpoint (`/pos/sell/checkout/finalize`) receives pre-committed payment list from session
- [x] 7.4 Update finalize endpoint to handle multi-stage payments (read session state, validate payments are already committed)
- [x] 7.5 Test end-to-end: add items → checkout → single payment (simple path) → verify receipt

## 8. Multi-Payment Test Scenarios

- [x] 8.1 Test single CASH payment (straightforward path)
- [x] 8.2 Test 2-stage payment: BRI + CASH
- [x] 8.3 Test 3-stage payment: BRI + BNI + CASH
- [x] 8.4 Test overpayment in final stage: verify change amount calculated and shown
- [x] 8.5 Test EDC reference validation: invalid format shows error, valid format accepted
- [x] 8.6 Test payment failure & retry: simulate API error, verify user can retry
- [x] 8.7 Test method change mid-stage: user starts BRI, changes to BNI before submit

## 9. Reload Recovery Testing

- [x] 9.1 Test reload after 1st payment commit: verify modal reopens with correct remainder
- [x] 9.2 Test reload after 2nd payment commit: verify full payment chain visible
- [x] 9.3 Test reload after session expiry: verify warning message and no auto-open
- [x] 9.4 Test reload with incomplete payment (remainder pending): verify user can continue to next stage

## 10. Error Handling & Edge Cases

- [x] 10.1 Test network timeout during stage submit: verify modal unlocks, user can retry
- [x] 10.2 Test duplicate submit (double-click [Proceed]): verify idempotency key prevents duplicate payment
- [x] 10.3 Test modal close button disabled during processing: verify user cannot close mid-flight
- [x] 10.4 Test amount input validation: reject negative, reject non-numeric, reject zero
- [x] 10.5 Test remainder becomes negative (overpayment): verify change is calculated as positive
- [x] 10.6 Test empty method selector: verify [Proceed] is disabled until method is selected

## 11. Database & Transaction Tracking

- [x] 11.1 Verify payment records store stage order (1st, 2nd, 3rd, etc.)
- [x] 11.2 Verify payment records store EDC reference for non-cash methods
- [x] 11.3 Test multi-stage payment appears as separate payment records in DB, not merged
- [x] 11.4 Test finalize can correctly aggregate multiple payment stages
- [x] 11.5 Verify sales posting includes all payment stages (totals reconcile)

## 12. UI/UX Polish

- [ ] 12.1 Verify remainder amount is prominent and easy to read
- [ ] 12.2 Verify payment chain list is clear (✓ method amount format)
- [ ] 12.3 Test spinner/loading indicator during processing (clear visual feedback)
- [ ] 12.4 Verify error messages are user-friendly and actionable
- [ ] 12.5 Test gratitude modal displays change amount in large, clear text
- [ ] 12.6 Verify receipt prints correctly in new tab (use existing printReceipt)

## 13. Backward Compatibility & Migration

- [ ] 13.1 Ensure existing `finalize` endpoint still works for any legacy single-batch submissions
- [ ] 13.2 Test old-style multi-payment modal (if still exists) does not conflict with new staged modal
- [ ] 13.3 Feature flag or config to enable/disable new staged flow (for rollback if needed)
- [ ] 13.4 Document migration path for any external systems that call POS payment APIs

## 14. Final QA & Documentation

- [ ] 14.1 Manual QA on full happy path: add items → 2-stage payment → receipt → done
- [ ] 14.2 Manual QA on unhappy paths: network errors, invalid input, session timeout
- [ ] 14.3 Load testing (optional): verify stage submission can handle concurrent requests
- [ ] 14.4 Document new `/pos/sell/checkout/stage-payment` endpoint (request/response format)
- [ ] 14.5 Document session state structure for payment chain recovery
- [ ] 14.6 Update POS transaction documentation if payment structure changed
