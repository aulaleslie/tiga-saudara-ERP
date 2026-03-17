## 1. Unify Non-Privileged Qty Control Composition

- [x] 1.1 Extract a shared qty-approval state-to-button renderer for non-privileged rows in `sell.blade.php`.
- [x] 1.2 Implement a shared top control strip markup in the order `[Reduce/Periksa slot][qty input][+]`.
- [x] 1.3 Apply the shared strip to non-serial non-privileged row rendering.
- [x] 1.4 Apply the shared strip to serial non-privileged row rendering while keeping serial action controls on a secondary line.

## 2. Stabilize Approval State Transitions After Periksa

- [x] 2.1 Update `js-check-qty-approval` pending flow to re-render from a fresh `cart_snapshot` after status check.
- [x] 2.2 Ensure approved state carries both approval token and approved quantity context into the shared slot renderer.
- [x] 2.3 Ensure rejected/cancelled state clears follow-up approval controls and returns slot to normal reduce-request state.
- [x] 2.4 Keep resilient fallback behavior when snapshot refresh fails (no broken controls, no inconsistent attributes).

## 3. Enforce Slot Layout Consistency

- [x] 3.1 Add/adjust CSS classes so the left reduce/approval slot keeps stable width across `Reduce`, `Periksa`, and approved states.
- [x] 3.2 Align spacing and alignment for the shared top control strip in both serial and non-serial rows.
- [x] 3.3 Remove or consolidate duplicated inline spacing/layout declarations that conflict with the shared structure.

## 4. Preserve Existing Role And Approval Semantics

- [x] 4.1 Verify privileged qty controls remain unchanged in behavior (direct decrease/increase paths).
- [x] 4.2 Verify non-privileged direct qty-input decrease remains blocked and continues guiding users to the reduce slot flow.
- [x] 4.3 Verify serial modal affordance remains accessible and functional after control-strip unification.

## 5. Add Regression Coverage

- [x] 5.1 Extend POS qty-reduction approval feature tests to cover deterministic state transitions after approval checks (`PENDING`/`APPROVED`/`REJECTED`/`CANCELLED` paths as applicable).
- [x] 5.2 Add/adjust tests that ensure latest request state is used when multiple qty-reduction requests exist on the same line.
- [x] 5.3 Add targeted assertions or test utilities that guard against regressions in snapshot-based qty approval rendering.

## 6. Validate End-to-End Behavior

- [x] 6.1 Run relevant POS feature tests for supervised cart actions and qty reduction.
- [x] 6.2 Manually validate row layout consistency for serial and non-serial items in the sell UI.
- [x] 6.3 Manually validate that `Periksa` and approved transitions are correct without full page refresh.
