## Why

POS checkout fails with `STOCK_UNAVAILABLE` / `SERIAL_TAX_STOCK_UNAVAILABLE` even when stock is physically available. The root cause is that `ResolvePosStockAllocationsService` forces the **cart line's** `tax_id` onto every serial when deciding which stock bucket to validate against, instead of using the serial's own `tax_id` (which reflects the bucket where stock actually lives). Serials received at non-PKP branch locations have `tax_id=NULL` and their stock sits in `quantity_non_tax`, but the resolver checks `quantity_tax` because the selling entity's product price has a tax configured. A second downstream bug exists: the posting adapter ignores the `tax_bucket_used` flag from allocations and re-derives the bucket decision, which would decrement the wrong bucket even if validation were fixed.

## What Changes

- **Serial product allocation**: Use each serial's own `tax_id` (not the cart line's `tax_id`) to determine which stock bucket (`quantity_tax` vs `quantity_non_tax`) to validate and group by. The cart line's `tax_id` controls pricing/tax calculation only, not stock bucket routing.
- **Non-serial product allocation**: Unify taxable and non-taxable lines to always scan `quantity_non_tax` first across all locations (priority order), then `quantity_tax` across all locations if still unfulfilled. Currently non-taxable lines only scan `quantity_non_tax`; taxable lines already use this two-phase approach.
- **Posting adapter stock decrement**: Use the `tax_bucket_used` flag from the resolver's allocations to determine which bucket to decrement, instead of re-deriving the bucket from `$sourceIsPkp` and snapshot `tax_id`.

## Capabilities

### New Capabilities
- `pos-stock-serial-bucket-resolution`: Correct stock bucket determination for serial products based on serial-level tax ownership rather than cart-line tax
- `pos-stock-posting-bucket-alignment`: Align posting adapter stock decrement with resolver allocation decisions via the `tax_bucket_used` flag

### Modified Capabilities

## Impact

- `Modules/Pos/Services/ResolvePosStockAllocationsService.php` — `allocateSerialLineUsingAssignedSerials()` resolvedTaxId logic, `allocateNonTaxableLineNonTaxBucketOnly()` unified with taxable path
- `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php` — stock decrement logic to use `tax_bucket_used` flag
- `Modules/Pos/Services/Adapters/SplitPosCheckoutPostingAdapter.php` — same decrement alignment if applicable
- Existing tests in `Modules/Pos/Tests/` may need updates to reflect corrected bucket logic
- No API changes, no migration changes, no UI changes
