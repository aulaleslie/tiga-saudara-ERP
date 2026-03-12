## Why

Mixed-business POS checkout can fail with a false `STOCK_UNAVAILABLE` error when a serial-required line has `tax_id=null` but the assigned serial exists as taxable stock in an allowed location. This blocks valid checkout completion in live cashier flows and should be fixed now to prevent avoidable transaction failures.

## What Changes

- Make checkout stock pre-check serial-aware so serial-required lines are validated against assigned serial records (status, location, and effective tax) instead of relying only on line-level `tax_id` buckets.
- Extend finalize pre-check inputs so stock resolver receives serial metadata required for correct allocation decisions on serial lines.
- Add actionable diagnostics for stock pre-check failures (unfulfilled line indices and product identifiers) in checkout failure payload/logs.
- Align split tax-bucket planning for serial-assigned lines so a serial-taxable line is not misclassified as `NON_TAX` when `line.tax_id` is null.
- Add regression coverage for mixed-business carts containing a taxed serial line plus non-serial non-tax line.

## Capabilities

### New Capabilities
- `pos-checkout-serial-stock-validation`: Validate serial-required lines using assigned serial context and produce actionable stock failure diagnostics during finalize pre-check.

### Modified Capabilities
- `pos-checkout-split-posting`: Update split tax-bucket determination rules for serial-assigned lines where line-level tax is absent.

## Impact

- Affected services: `Modules/Pos/Services/FinalizePosCheckoutService.php`, `Modules/Pos/Services/ResolvePosStockAllocationsService.php`, and `Modules/Pos/Services/PosCheckoutSplitPlannerService.php`.
- Affected checkout error payload/log content for `STOCK_UNAVAILABLE`.
- Regression tests required for mixed-business serial checkout and split-group tax classification behavior.
