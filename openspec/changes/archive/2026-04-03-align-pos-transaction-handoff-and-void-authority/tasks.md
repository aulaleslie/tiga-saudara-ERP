## 1. Split POS draft load and cancel authorization

- [x] 1.1 Refactor the POS transaction policy/service layer so draft loading is authorized by same-setting scope plus `pos.transactions.load`, without owner-based restrictions for mutable drafts.
- [x] 1.2 Remove owner or `pos.transactions.edit.any` assumptions from transaction load entry points and align the transaction list/detail load affordances with the new handoff rule.
- [x] 1.3 Audit runtime uses of `pos.transactions.edit.any` in the POS transaction flow and narrow or remove any branches that only existed to support cross-user draft loading.

## 2. Add approval-backed transaction cancel authority

- [x] 2.1 Introduce a dedicated POS transaction cancel approval action in the approval request, approval token, and supervisor approval mapping layers.
- [x] 2.2 Refactor POS transaction cancellation so mutable transaction cancel requires direct `pos.void` authority or a valid approval token, while completed transactions remain non-cancellable.
- [x] 2.3 Update transaction cancel controllers or request handling so approval-backed cancel attempts can return the same approval-required state used by other supervised POS destructive actions.

## 3. Align POS transaction UI with the new authority model

- [x] 3.1 Update the POS transaction list UI to support approval-backed cancel interactions, including pending, approved, continue, and discard states.
- [x] 3.2 Update the POS transaction detail UI to mirror the same cancel approval interaction model and to avoid implying that ownership alone grants destructive authority.
- [x] 3.3 Ensure cancel affordances and messages clearly distinguish collaborative draft loading from destructive cancellation authority.

## 4. Refresh regression coverage

- [x] 4.1 Replace transaction load tests that expect owner-only cross-user restrictions with coverage for same-setting load-any-draft behavior and missing-permission rejection.
- [x] 4.2 Replace transaction cancel tests that expect owner-based success with coverage for direct `pos.void`, approval-required fallback, approved-token success, and immutable transaction rejection.
- [x] 4.3 Add role-matrix or approval-flow regression coverage to verify cashier and floor-staff handoff works without granting destructive cancel authority by default.
