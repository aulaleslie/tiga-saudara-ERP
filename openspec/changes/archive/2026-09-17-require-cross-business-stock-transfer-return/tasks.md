## 1. Route Policy

- [x] 1.1 Update `TransferRoutePolicyResolver::resolveClassification()` so every cross-business route sets `mandatory_return = true`, while same-business stays false and destination classification remains PKP-dependent.
- [x] 1.2 Update focused resolver and approval snapshot tests for non-PKP to non-PKP, same-business, and PKP-involved routes; verify draft or pending transfers receive the new snapshot only when approved.

## 2. Receipt and Return Lifecycle

- [x] 2.1 Add a focused non-PKP to non-PKP transfer scenario that approves an exact forward receipt, checks full-product obligations and `AWAITING_RETURN`, then completes return dispatch and return receipt before `COMPLETED`.
- [x] 2.2 Verify a previously approved `mandatory_return = false` snapshot still completes without new obligations after forward receipt, and confirm the transfer detail shows the stored policy decision.

## 3. Focused Verification

- [x] 3.1 Run the affected route-policy and transfer lifecycle tests with focused `php artisan test` filters; resolve any regressions caused by the changed non-PKP to non-PKP expectation.
