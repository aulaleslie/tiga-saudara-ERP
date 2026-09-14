## 1. Permission-Aware Detail Loading

- [x] 1.1 Refactor `TransferStockController::show` to always eager-load safe product identity while determining stock visibility through the established `stockTransfers.view-system-stock` authorization path.
- [x] 1.2 Conditionally eager-load `products.returnObligation`, `routePolicies`, and `movementReturnObligations.product` only for stock-visible viewers, using bounded eager-loading queries that avoid per-line N+1 access.

## 2. Protected View Evaluation

- [x] 2.1 Move legacy `TransferProduct::returnObligation` evaluation and all obligation-dependent markup inside the stock-visibility permission branch in `transfers/show.blade.php`.
- [x] 2.2 Verify the privileged branch continues to present existing legacy and version-2 route-policy/obligation information while the blind branch retains permitted product identity and lifecycle context.

## 3. Focused Regression Verification

- [x] 3.1 Add a blind-user HTTP regression case with Eloquent lazy loading disabled that proves transfer detail renders, permitted identity/context remains visible, and distinctive legacy/v2 obligation and policy values are absent.
- [x] 3.2 Add a stock-visible-user HTTP regression case with Eloquent lazy loading disabled that proves legacy line obligations and version-2 movement obligations render without lazy-loading violations.
- [x] 3.3 Run the focused transfer-detail visibility test file or filter and confirm both strict-loading and confidentiality cases pass.
