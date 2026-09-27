## Why

POS checkout split posting can currently classify PKP-owned bundled component allocations as `NON_TAX` when the customer-facing POS line has no explicit `tax_id`, even when the source owner is taxable and the stock is held in the tax quantity bucket. This produces incorrect Sales, dispatch, stock, and POS Return source documents for taxable owners such as CV TIGA NUSA COMPUTER.

## What Changes

- Treat POS sale allocations as taxable when the source owner setting is PKP/taxable.
- Treat POS sale allocations as taxable when the allocation consumes `quantity_tax`, even if the POS line has no explicit tax.
- Resolve missing POS sale tax ids through fallback order: explicit line tax, product or product-price sale tax, allocation or stock tax, default tax, then latest active tax.
- Preserve non-PKP owner behavior: non-PKP allocations remain `NON_TAX` and do not persist dispatch tax.
- Apply the correction only to future POS checkout posting; no historical data repair or backfill is included.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `pos-checkout-split-posting`: PKP-owned and tax-bucket POS allocations must resolve to taxable split buckets with fallback tax resolution instead of becoming `NON_TAX` because the POS line lacks `tax_id`.

## Impact

- Affects POS cart tax resolution, stock allocation tax snapshots, split planning tax bucket resolution, split posting persistence, and focused POS checkout tests.
- Primary code areas: `Modules/Pos/Services/PosCartService.php`, `Modules/Pos/Services/ResolvePosStockAllocationsService.php`, `Modules/Pos/Services/PosCheckoutSplitPlannerService.php`, `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`, and related POS checkout test fixtures.
- No database migrations are expected.
- Historical posted checkouts remain unchanged.
