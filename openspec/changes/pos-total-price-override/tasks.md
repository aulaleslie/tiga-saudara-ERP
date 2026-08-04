## 1. Permission and approval foundation

- [x] 1.1 Add `pos.overrides.total-price` to the centralized POS permission registry, exception capability cluster, supported manager bundle, and permission-matrix tests.
- [x] 1.2 Add the `TOTAL_PRICE_OVERRIDE` action type and corresponding supervisor-audit action mapping to POS approval entities and services.
- [x] 1.3 Extend cart-action authorization, approval-request validation, supervisor approval/rejection authorization, and token validation for the new action.
- [x] 1.4 Extend the supervisor approval queue API and UI to display source total, target total, delta, reason, and cart/session target for total-price requests.

## 2. Exact total allocation and cart state

- [x] 2.1 Implement a minor-unit proportional cart-total allocation service with deterministic largest-remainder rounding and exact target reconciliation.
- [x] 2.2 Add a canonical cart fingerprint that includes mutable pricing inputs and create request payload/audit context containing source total, target total, fingerprint, reason, and final allocation.
- [x] 2.3 Implement the cart-total override mutation: validate a non-empty mutable cart and non-negative target, authorize or consume an approval token, allocate exact line totals, set effective unit prices, and mark rows `TOTAL_OVERRIDE`.
- [x] 2.4 Update cart snapshot construction to expose authoritative override metadata and cart-level pending/approved `TOTAL_PRICE_OVERRIDE` state separately from row approval state.
- [x] 2.5 Invalidate total-price requests and clear an applied total override before any relevant cart mutation or normal repricing; reject token execution when the fingerprint no longer matches.
- [x] 2.6 Protect total-overridden packed and bundle rows from quantity/customer-tier/packing repricing until the override has been invalidated.

## 3. HTTP and cashier experience

- [x] 3.1 Add a validated POS cart-total-override endpoint and request class that accepts target total, optional reason, and optional approval token.
- [x] 3.2 Add a cart-total override modal/control to the POS sell screen showing current total, target total, validation feedback, and effective-price/line-total result.
- [x] 3.3 Integrate the control with `ApprovalManager` so direct users apply immediately and non-authorized users progress through request, status check, token, and explicit confirmation.
- [x] 3.4 Render pending, approved, rejected, and invalidated total-override states without interfering with existing line-price, quantity, remove, or clear-cart approval controls.

## 4. Checkout integrity and audit verification

- [x] 4.1 Verify totals calculator, checkout snapshot, payment validation, receipt mapping, and direct checkout posting use authoritative overridden line totals.
- [x] 4.2 Verify split-owner checkout planning/posting allocates and reconciles to the exact overridden POS total.
- [x] 4.3 Add focused feature tests for direct permission, absent-permission request, supervisor approve/reject, token replay, Super Admin bypass, zero target, and negative target rejection.
- [x] 4.4 Add allocation tests covering multi-row deterministic rounding and a quantity-three Rp10.500-to-Rp10.000 example that remains one visible row with an exact line total.
- [x] 4.5 Add mutation-invalidation tests for add/remove/quantity/customer-tier/serial/line-price changes and tests that packed, bundle, serial, and split-owner carts preserve checkout integrity.
- [x] 4.6 Run focused POS tests, then the project’s appropriate broader PHP test command; resolve regressions before marking the change ready.
