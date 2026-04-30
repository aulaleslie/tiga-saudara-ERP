## 1. Composition Resolver Refactor

- [x] 1.1 Refactor `bundleCompositionByTransactionLine` flow in `Modules/Pos/Services/PosReceiptService.php` to resolve composition per bundled line using multi-group aggregation rather than single-group return.
- [x] 1.2 Implement helper logic to include component-only split groups (`parent_qty = 0`) when they belong to the same bundled parent line.
- [x] 1.3 Preserve consume-once behavior at bundled-line granularity so grouped composition cannot leak into subsequent rows.
- [x] 1.4 Keep strict bundle-line gating and `line_meta` fallback behavior (fallback only when reconstructed composition is empty).

## 2. Regression Test Coverage

- [x] 2.1 Add/update feature test for 2-owner split bundle checkout to assert receipt and transaction detail show the complete component set under the bundled line.
- [x] 2.2 Add feature test for 3-owner split bundle checkout (parent owner + two component owners) to assert both component rows are shown for one bundled parent line.
- [x] 2.3 Add feature test for mixed rows (same parent product with bundled and non-bundled rows) to assert no component leakage or duplication across rows.
- [x] 2.4 Ensure assertions validate customer-facing contract only (name + quantity, no owner/allocation/price internals in component rows).

## 3. Verification and Safety Checks

- [x] 3.1 Run focused POS tests covering split posting, split bundle receipt/detail rendering, and the new regression scenarios.
- [x] 3.2 Confirm no regressions in existing bundle presentation behavior for draft/loaded transactions.
- [x] 3.3 Document final verification evidence in the change notes/PR summary (failing path covered and pass criteria met).
