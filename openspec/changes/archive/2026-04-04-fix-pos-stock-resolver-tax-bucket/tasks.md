## 1. Fix Serial Stock Bucket Resolution

- [x] 1.1 In `ResolvePosStockAllocationsService::allocateSerialLineUsingAssignedSerials()`, change `resolvedTaxId` to use `$record->tax_id` instead of `$lineTaxId` override
- [x] 1.2 Write unit test: mixed serials (tax and non-tax) across locations all pass validation when stock is in correct buckets
- [x] 1.3 Write unit test: serial with `tax_id=NULL` at location with `quantity_non_tax >= 1` passes even when `quantity_tax = 0`
- [x] 1.4 Write unit test: serial with `tax_id=2` at location with `quantity_tax = 0` fails with `SERIAL_TAX_STOCK_UNAVAILABLE`

## 2. Unify Non-Serial Allocation Strategy

- [x] 2.1 Route non-taxable lines through the same two-phase (non-tax first, then tax fallback) allocation path used by taxable lines in `ResolvePosStockAllocationsService::resolve()`
- [x] 2.2 Write unit test: non-taxable line allocates from `quantity_non_tax` first across priority locations
- [x] 2.3 Write unit test: non-taxable line falls back to `quantity_tax` when `quantity_non_tax` is exhausted

## 3. Fix Posting Adapter Stock Decrement

- [x] 3.1 In `InlinePosCheckoutPostingAdapter`, replace `$effectiveTaxId` bucket derivation with `tax_bucket_used` flag from allocation chunk for stock decrement and inline validation
- [x] 3.2 Update `Transaction` record creation to use `tax_bucket_used` flag for `quantity_tax`/`quantity_non_tax` fields
- [x] 3.3 Audit `SplitPosCheckoutPostingAdapter` for the same `$effectiveTaxId` pattern and apply same fix if present
- [x] 3.4 Write unit test: allocation with `tax_bucket_used=false` decrements `quantity_non_tax` even when line has tax_id

## 4. Update Existing Tests

- [x] 4.1 Review and update existing stock resolver tests to reflect corrected serial bucket logic
- [x] 4.2 Review and update existing posting adapter tests to reflect `tax_bucket_used` decrement logic
