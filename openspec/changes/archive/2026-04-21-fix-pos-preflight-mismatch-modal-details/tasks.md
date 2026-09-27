## 1. Preflight Error Propagation Hardening

- [x] 1.1 Update POS sell `jsonRequest` error path to preserve structured backend error data (`message`, `code`, `details`, status) instead of throwing plain message-only errors.
- [x] 1.2 Ensure checkout preflight click-handler mismatch branch reliably detects `details.unfulfilled_lines` and `details.invalid_lines` from structured errors.
- [x] 1.3 Verify generic validation alert fallback only executes when structured mismatch details are truly unavailable.

## 2. Mismatch Modal Data Normalization

- [x] 2.1 Refactor mismatch modal row mapping to treat `requested_qty` and `allocated_qty` as canonical fields and compute shortage deterministically in frontend.
- [x] 2.2 Add resilient product labeling fallback (`product_name` → `product_code` → `Product #<id>`) for mismatch line rendering.
- [x] 2.3 Confirm mismatch modal remains cart-context only (no staged modal open) after preflight failure and after dialog close.

## 3. Regression Test Coverage

- [x] 3.1 Add/extend POS checkout flow tests to cover structured preflight error propagation into UI mismatch routing.
- [x] 3.2 Add/extend tests for mismatch modal data fallback behavior when `product_name`/`shortage` are absent but canonical fields are present.
- [x] 3.3 Run targeted POS test suites for preflight success/failure gating and ensure existing staged-payment success flow remains unchanged.

## 4. Verification and Change Readiness

- [ ] 4.1 Manually validate cashier path: click `Pilih Pembayaran` on insufficient stock cart and confirm detailed mismatch modal appears instead of generic alert.
- [x] 4.2 Verify no contract regressions in preflight API payload shape (`code`, `message`, `details.unfulfilled_lines`).
- [x] 4.3 Update any relevant inline notes/docs if test harness or helper semantics changed due to structured error handling.
