## 1. Regression Coverage

- [x] 1.1 Add a split-posting regression test for a stock-managed serial-tracked parent product with a selected stock-managed bundle child where checkout finalize succeeds.
- [x] 1.2 Assert the single-source regression decrements parent stock once, child stock once, marks the assigned serial sold, and links the serial to the created dispatch detail.
- [x] 1.3 Add a multi-source regression test where two assigned parent serials resolve into two split groups and the selected bundle child quantity is deducted exactly twice total.
- [x] 1.4 Assert multi-source child stock transactions preserve child allocation source location, source setting, and `tax_bucket_used` bucket behavior.

## 2. Split Planner Allocation Fix

- [x] 2.1 Update serial-line split planning so each grouped serial parent line receives a non-empty parent allocation under the grouped numeric index and grouped `"{index}_P"` key.
- [x] 2.2 Ensure grouped serial parent allocations carry source location, source setting, allocated quantity, `tax_bucket_used`, tax policy snapshot, and grouped serial numbers.
- [x] 2.3 Partition bundle child allocations per grouped parent line using grouped parent quantity and child quantity-per-bundle.
- [x] 2.4 Ensure total grouped child allocation quantity across all split groups equals the original resolver-required child quantity.

## 3. Posting Contract Alignment

- [x] 3.1 Verify `InlinePosCheckoutPostingAdapter` can consume grouped serial parent allocations without re-deriving source location or stock bucket.
- [x] 3.2 Verify serial lifecycle updates use grouped serial data and still write sold status, dispatch linkage, serial history, and sales-order serial tracking.
- [x] 3.3 Verify child stock movement uses child allocation source location, source setting, and `tax_bucket_used` without inheriting the parent group's stock bucket.

## 4. Verification

- [x] 4.1 Run the focused POS split posting, bundle cart/checkout, serial checkout, and stock allocation tests affected by this change.
- [x] 4.2 Run the broader POS critical path test group if available.
- [x] 4.3 Reproduce the original product #1 scenario or equivalent fixture and confirm checkout no longer fails with missing parent allocation.
