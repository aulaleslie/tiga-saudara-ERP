## 1. Serial-Aware Pre-Check Contract

- [x] 1.1 Update `FinalizePosCheckoutService` line payload construction to pass serial-required metadata and assigned-serial identifiers into stock pre-check input.
- [x] 1.2 Implement serial-aware validation path in `ResolvePosStockAllocationsService` that verifies assigned serial status/location/tax context before marking a line fulfilled.
- [x] 1.3 Preserve existing non-serial quantity-bucket behavior and compatibility output (`unfulfilled_lines`) while adding per-line reason diagnostics for failures.

## 2. Split Planner Tax Bucket Alignment

- [x] 2.1 Update `PosCheckoutSplitPlannerService` effective-tax resolution order so serial-assigned lines with null `line.tax_id` derive tax bucket from serial context before fallback.
- [x] 2.2 Add planner-level regression tests for serial-assigned taxable lines to ensure they are not classified as `NON_TAX` when serial tax context is present.

## 3. Failure Diagnostics and Regression Coverage

- [x] 3.1 Add/extend finalize checkout tests for mixed-business cart with taxed serial line + non-serial non-tax line to verify checkout succeeds.
- [x] 3.2 Add tests that assert `STOCK_UNAVAILABLE` includes actionable failing-line diagnostics (line index, product identifier, reason code).
- [x] 3.3 Run targeted POS checkout test suite and record results in project notes for this fix.
