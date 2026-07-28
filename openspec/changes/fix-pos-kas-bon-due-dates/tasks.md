## 1. Kas Bon checkout-context propagation

- [x] 1.1 Trace and correct the staged payment-chain/finalize handoff so an authorized Kas Bon checkout always supplies its selected payment-term ID to the posting context.
- [x] 1.2 Resolve the payment term and calculated due date from one checkout posting date, then propagate the same debt context to direct and split owner-sale posting.
- [x] 1.3 Preserve current validation by rejecting debt checkout without a valid selected payment term and leaving non-debt checkout behavior unchanged.

## 2. Regression coverage

- [x] 2.1 Add a staged Kas Bon zero-down-payment feature test that verifies the generated Sale has the selected term and `checkout date + longevity` due date.
- [x] 2.2 Add a staged Kas Bon partial-down-payment feature test that verifies payment allocation and the selected term/due-date invariant.
- [x] 2.3 Add a split-owner Kas Bon feature test that verifies every generated Sale has the selected term and the same calculated due date.

## 3. Verification

- [x] 3.1 Run the focused POS debt and split-posting test suites to verify down-payment handling, full-payment scenarios, and split-ownership propagation.
- [x] 3.2 Run the core POS checkout test suite to verify no regressions in direct checkout logic.
