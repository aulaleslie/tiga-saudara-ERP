# Tasks

## 1. Bundle Quantity Reconstruction

- [x] 1.1 Update snapshot-based bundle composition mapping in `PosReceiptService` to calculate total component quantity as parent line quantity multiplied by component quantity per bundle, and verify focused service tests cover both `quantity` and legacy `qty` snapshot fields.
- [x] 1.2 Apply the shared snapshot quantity mapping to completed receipt fallback, draft/loaded receipts, and transaction detail composition while leaving persisted Sales/dispatch totals unchanged; verify focused tests prove persisted totals are not multiplied twice.

## 2. Focused Regression Verification

- [x] 2.1 Add focused regressions for parent quantity 2 with component multiplier 1 (`x2`) and multiplier 2 (`x4`) across the affected receipt/detail paths, then run only the relevant POS receipt and bundle reconstruction test files or filters and confirm they pass.
- [x] 2.2 Confirm the existing receipt Blade template has no structural or styling changes and review the implementation diff for changes outside the receipt quantity mapping and focused tests.
