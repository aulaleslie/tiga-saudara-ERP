# Tasks

## 1. Global HPP Request Boundary

- [ ] 1.1 Add the dedicated global-HPP route, controller action, and Form Request under `products.manage_cross_business_prices`; verify focused access tests deny unauthorized users and reject non-stock-managed products.
- [ ] 1.2 Add trusted loaded-state evidence for the complete current business membership, relevant row presence, versions, and average values; verify focused tests reject row, value, and business-membership drift without writes.

## 2. Atomic Synchronization and Audit

- [ ] 2.1 Implement the transactional global-HPP service operation with row locking, positive Rupiah validation, missing-row creation through established defaults, synchronization to all current businesses, and preservation of unrelated price/tax fields; verify focused feature tests cover uniform values, divergent normalization, missing rows, invalid input, and rollback.
- [ ] 2.2 Extend product price feed recording and masking for manual average-purchase-price changes under one operation UUID, omitting unchanged rows; verify focused tests cover grouped before/after snapshots, no-op behavior, actor/source identity, rollback, and purchase-price visibility.

## 3. Cross-Business Price Dialog

- [ ] 3.1 Add `Ubah HPP` beside `Ubah`, retain the read-only per-business average column, and render the modal's current value, Indonesian global-impact text, historical-snapshot statement, future purchase-recalculation warning, and conditional divergence warning; verify focused view tests assert the action, dialog text, and non-stock disabled/hidden state.
- [ ] 3.2 Implement isolated modal currency input, validation restoration/reopen behavior, general-edit interaction blocking, duplicate-submit protection, and `Menyimpan...` state without submitting the general pricing form; verify focused browser-facing DOM/JavaScript assertions cover the interaction hooks and canonical value submission.
- [ ] 3.3 Redirect a successful save to the same product price-management page with refreshed averages and `Harga Beli Rata-rata berhasil diperbarui untuk seluruh bisnis.`; verify a focused feature test covers redirect, flash feedback, and updated displayed values.

## 4. Focused Verification

- [ ] 4.1 Run the new global-HPP focused tests plus the directly affected existing cross-business price access/mask/feed tests, fix any regressions, and record the exact focused commands and results; do not run or require the full application test suite.
- [ ] 4.2 Run `openspec validate add-global-hpp-update-dialog --strict` and verify the completed change artifacts and requirement deltas remain valid.
