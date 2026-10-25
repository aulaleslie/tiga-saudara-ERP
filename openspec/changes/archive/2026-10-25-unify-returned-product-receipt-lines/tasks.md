# Tasks

## 1. Recorded price presentation

- [x] 1.1 Add a focused regression fixture reproducing transaction 8611 without live IDs: packed 12 PCS / Rp85,000, stored unit price Rp7,083.33, completed one-PCS refund Rp7,100, remaining Sale quantity 11 and subtotal Rp77,900; verify the test rejects the current 2/9 cent-price split and expects one 11-PCS breakdown at Rp7,083.33.
- [x] 1.2 Separate recorded display-price resolution from assigned charges in the shared completed receipt projection, honoring proven linked Sale edits and excluding catalog/component allocation prices; verify the regression payload retains Rp77,900 while its display price is Rp7,083.33 and printing leaves financial records unchanged.
- [x] 1.3 Remove remainder-based quantity splitting from displayed unit breakdowns while preserving financial allocation arithmetic, stored currency precision, and existing total formatting; verify focused uniform-price owner-split and affected fractional-quantity cases show no artificial adjacent-cent groups.

## 2. Packing and existing price behavior

- [x] 2.1 Preserve recorded box/loose unit bases and labels, using the persisted base-unit selling price for loose remainders of a box purchase; add and run focused mixed-packing and box-to-loose checks, including proof that the catalog loose price Rp8,000 does not replace Rp7,083.33 in the regression.
- [x] 2.2 Preserve legitimate later Sale price changes, genuine distinct-price explanations, and existing packed generic notices without fabricating adjusted unit prices; verify focused existing price-adjustment methods and any necessary targeted regression additions pass.
- [x] 2.3 Verify full-return omission, historical snapshots, and relevant bundle/serial preservation using existing focused receipt methods, adding only missing assertions directly affected by this change; record the selected tests and outcomes.

## 3. Focused integration verification

- [x] 3.1 Extend the existing completed receipt endpoint-parity test with the rounded partial-return case; verify ordinary checkout, transaction, and global-payment print/reprint paths show equivalent quantity, recorded price, and authoritative total while retaining action-specific audits and permissions.
- [x] 3.2 Run only the affected receipt test methods/classes with `php artisan test --filter=...` in a working project environment, inspect the final diff for unintended financial changes, and record commands/results or the precise environment blocker; do not run or require a full suite.
