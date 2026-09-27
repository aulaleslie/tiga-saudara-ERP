## 1. Backend Preflight Validation

- [x] 1.1 Add POS checkout preflight endpoint and route for read-only validation before payment modal open.
- [x] 1.2 Refactor/extend checkout validation service so preflight and finalize share the same serial/stock validation path.
- [x] 1.3 Return structured preflight failure payload (`code`, `message`, `details.unfulfilled_lines`) with machine-readable reason codes.
- [x] 1.4 Add/adjust feature tests for preflight pass/fail behavior, including serial mismatch and insufficient stock cases.

## 2. Frontend Checkout Gating and Mismatch Dialog

- [x] 2.1 Update `Pilih Pembayaran` click flow in POS sell page to call preflight before invoking staged payment modal.
- [x] 2.2 Implement mismatch dialog/modal rendering from `details.unfulfilled_lines` and block staged modal when preflight fails.
- [x] 2.3 Ensure mismatch dialog close returns user to POS cart context without opening staged payment.
- [x] 2.4 Add frontend regression checks for success path (preflight pass opens staged modal) and failure path (preflight fail shows mismatch dialog).

## 3. Staged Payment Flow Consistency

- [x] 3.1 Align staged payment completion UX so success-like messaging only appears after finalize success.
- [x] 3.2 Verify finalize failure handling remains authoritative even after preflight pass (race condition coverage).
- [x] 3.3 Add/update tests for staged flow ordering and error presentation consistency.

## 4. Validation and Rollout Readiness

- [x] 4.1 Run targeted POS feature test suites covering checkout preflight, staged checkout wiring, and serial-stock validation.
- [x] 4.2 Verify no regression in existing checkout finalize/idempotency behavior.
- [x] 4.3 Document endpoint/contract changes in change artifacts and confirm OpenSpec apply prerequisites are satisfied.
