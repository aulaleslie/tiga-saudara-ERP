## 1. Row Identity Regression Coverage

- [x] 1.1 Add a normal Sales persistence test proving the same parent product with two different bundles creates distinct parent details and correctly linked component rows.
- [x] 1.2 Add a persistence test proving bundled and ordinary instances of the same product remain distinct and only the bundled parent owns component rows.
- [x] 1.3 Add a persistence test proving a shared component product under multiple bundle rows produces separate parent-linked component records.
- [x] 1.4 Add a cart/update test proving removal of one bundled row leaves the other row and its captured components unchanged.

## 2. Quantity and Snapshot Regression Coverage

- [x] 2.1 Add a create-flow test that changes parent quantity through increase, decrease, and increase steps and verifies final component quantity equals parent quantity multiplied by quantity per bundle exactly once.
- [x] 2.2 Add an edit-flow test that hydrates a persisted draft, repeats the quantity-change sequence, and verifies reconstructed quantity per bundle and final persisted quantity.
- [x] 2.3 Extend draft-drift coverage to verify changed live component identity, quantity, and informational allocation do not replace the captured composition after acknowledgement.

## 3. Linkage and Atomicity Regression Coverage

- [x] 3.1 Assert in create-flow coverage that every linked component's `sale_detail_id` points to its owning parent and its `sale_id` equals the parent detail's `sale_id`.
- [x] 3.2 Assert in editable-update coverage that replacement components point to replacement parent details and no stale component rows remain.
- [x] 3.3 Add a controlled component-write failure test for Sale creation and verify the header, parent details, and components all roll back.
- [x] 3.4 Add a controlled component-write failure test for editable draft update and verify the previously committed header, details, and components remain unchanged.

## 4. Deferred Stock Contract

- [x] 4.1 Consolidate or extend coverage proving insufficient component stock does not block normal Sale creation and causes no inventory mutation.
- [x] 4.2 Extend coverage proving an editable draft may increase component demand beyond stock without inventory mutation.
- [x] 4.3 Verify dispatch rejects unavailable stock-managed component quantity at the selected location before inventory movement.

## 5. Evidence-Driven Corrections and Verification

- [x] 5.1 Run the new focused tests against the existing implementation and record which invariants pass without production changes.
- [x] 5.2 If and only if a new regression test fails, make the smallest normal Sales cart or persistence correction required for that failed invariant; do not change POS split posting, standalone null-parent handling, dispatch identity, or schema.
- [x] 5.3 Run the focused normal Sales bundle, lifecycle, monetary-edit, stock-validation, and dispatch regression suites.
- [x] 5.4 Run the project's higher-confidence PHP verification command appropriate to the final change size and confirm no unrelated behavior regressed.
- [x] 5.5 Record the standalone POS/legacy null-parent dispatch inconsistency as a Sequence 6 or 7 follow-up without implementing it in this change.
