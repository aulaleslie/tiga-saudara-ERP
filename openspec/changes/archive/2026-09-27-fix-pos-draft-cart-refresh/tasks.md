# Tasks

## 1. Specification Compatibility

- [x] 1.1 Normalize the existing `openspec/specs/pos-sell-save-new/spec.md` to a valid main-spec `Purpose` and `Requirements` structure without changing requirement behavior, and verify OpenSpec recognizes its requirements and no longer reports an archive-refusal warning for this delta.

## 2. Authoritative Save Response

- [x] 2.1 Extend the successful POS save-and-new response with the post-save empty `cart_snapshot`, and verify the focused save-and-new feature test asserts zero lines, cleared customer/note state, and no active transaction in that response.
- [x] 2.2 Preserve the existing transaction metadata and failure response contracts, and verify the focused permission, empty-cart, and successful-save cases in `POSTransactionSaveAndNewTest` pass.

## 3. POS Shell State Transition

- [x] 3.1 Update the save-draft success handler to render the returned `cart_snapshot` directly without a follow-up cart GET, and verify a focused rendered-source regression test detects direct rendering and the absence of `refreshCart()` in that success path.
- [x] 3.2 Make request cleanup restore presentation without unconditionally enabling the save-draft control, and verify a focused rendered-source regression test confirms empty-cart button state remains owned by `renderCart()`.
- [x] 3.3 Cancel scheduled note submission and invalidate pending note-response rendering on confirmed save success before rendering the new snapshot, and verify focused tests cover the transaction-boundary invalidation while the failure path preserves current state.

## 4. Focused Verification and Human Browser Check

- [x] 4.1 Run only the focused POS save-and-new and POS UI lifecycle test files relevant to this change and record that they pass; do not run or require the full test suite.
- [ ] 4.2 Provide the human tester with a browser checklist covering successful draft save, empty cart and reset fields, disabled save/checkout controls, success modal/transaction code, next-customer entry, and failed-save state preservation; record the human's result when supplied.
