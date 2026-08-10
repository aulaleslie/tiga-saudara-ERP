## 1. Centralize payable lifecycle eligibility

- [ ] 1.1 Add clearly named reusable global-payment eligibility predicates/scopes for Sale and Purchase using the approved status sets, excluding full `RETURNED`.
- [ ] 1.2 Confirm normal non-global callers of the existing sales status scope retain their current lifecycle behavior.

## 2. Apply the policy to global payment surfaces

- [ ] 2.1 Update global sales list, summary-card filters, detail/history, candidate loading, starting-sale checks, and locked allocation validation to use sales global-payment eligibility.
- [ ] 2.2 Update global purchase list, summary-card filters, detail/history, candidate loading, starting-purchase checks, and locked allocation validation to use purchase global-payment eligibility.
- [ ] 2.3 Preserve non-archived and canonical positive-live-balance requirements for payment actions and allocation candidates.

## 3. Verify lifecycle and settlement behavior

- [ ] 3.1 Add sales feature coverage proving `RETURNED PARTIALLY` is visible, selectable, and payable when outstanding, while `RETURNED` is unavailable and rejected on a tampered submission.
- [ ] 3.2 Add purchase feature coverage proving `RECEIVED PARTIALLY` and `RETURNED PARTIALLY` are visible, selectable, and payable when outstanding, while `RETURNED` is unavailable and rejected on a tampered submission.
- [ ] 3.3 Run focused global sales and purchase payment tests and the relevant normal-workflow regression tests.
