## 1. Normalize Quantity-Approval Render State

- [x] 1.1 Add a canonical quantity-approval state mapper in POS sell view logic that normalizes `request_id/requestId`, token, status, and approved qty fields.
- [x] 1.2 Refactor both serial and non-serial cart row render branches to consume the normalized state for qty reduction controls.
- [x] 1.3 Enforce server `line.pending_approvals` precedence after refresh, with client cache used only as transient fallback.

## 2. Fix Cart Snapshot Contract Usage

- [x] 2.1 Update qty-reduction post-request refresh flow to pass `response.cart_snapshot` into `renderCart`.
- [x] 2.2 Guard refresh fallback behavior so UI still re-renders correctly when cart refresh request fails.
- [x] 2.3 Verify all `/pos/sell/cart` render paths use the same snapshot contract.

## 3. Align Qty Reduction State Transitions With Supervised Action Patterns

- [x] 3.1 Ensure pending qty requests render `Periksa Persetujuan` with valid request binding.
- [x] 3.2 Ensure approved qty requests render proceed control with approval token and approved qty.
- [x] 3.3 Ensure rejected/cancelled qty requests clear follow-up controls and return to normal reduce-request path.

## 4. Add Regression Coverage

- [x] 4.1 Extend POS approval flow tests to cover pending qty-approval visibility immediately after request submission.
- [x] 4.2 Add/extend test coverage for qty-approval visibility after cart snapshot reload/page refresh.
- [x] 4.3 Run relevant POS feature tests for supervised cart actions and confirm no regressions in delete/clear approval flows.
