## Why

Automatically calculated Sales, Purchase, and POS row totals can end in inconvenient fractional or non-hundred Rupiah values after discounts and tax. Each business needs a consistent way to round only customer-facing, automatically priced row totals while preserving deliberate manual prices and existing internal bundle allocations.

## What Changes

- Add a per-business row-total rounding increment, defaulting to Rp100 and allowing zero to disable automatic rounding.
- Round each eligible automatically calculated commercial row independently using half-up rounding after applicable tax, then derive the row's pre-tax amount and tax so they reconcile to the rounded tax-inclusive total.
- Apply rounding during user-driven automatic pricing interactions in Sales, Purchase, and POS, without separately rounding document grand totals, global discounts, shipping, owner splits, returns, or internal allocation rows.
- Preserve exact user-entered unit prices and row totals, including approved POS overrides, rather than applying automatic rounding to them.
- Preserve existing bundle component informational/allocation prices and assign the customer-facing rounding difference to the bundle parent residual settlement.
- Preserve historical documents and avoid mutating a draft merely because it is loaded.
- Verify only affected settings, pricing, tax, bundle settlement, checkout, persistence, and focused regression behavior.

## Capabilities

### New Capabilities
- `transaction-row-total-rounding`: Defines per-business configuration and common Sales, Purchase, and POS rules for eligible automatic tax-inclusive row rounding, manual-price bypass, persistence, and non-rounding boundaries.

### Modified Capabilities
- `sale-cart-pricing`: Automatically priced visible Sales rows, including bundle parent rows, use the configured rounded tax-inclusive row total while bundle component informational values remain unchanged.
- `pos-bundle-sale-price-allocation`: POS bundle customer totals may be rounded while fixed component allocations remain unchanged and the parent residual absorbs the difference.
- `pos-conversion-packing-pricing`: Automatically priced packed rows round their completed customer-facing line total without changing their internal packing calculation; explicit overrides remain authoritative.

## Impact

- Business configuration UI, request validation, Setting persistence, defaults, factories, and migrations.
- Sales and Purchase Livewire cart calculations, pricing-source persistence, backend normalizers, detail tax allocation, create/edit hydration, and printed totals.
- POS canonical minor-unit totals calculation, cart snapshots, drafts, payment validation, receipts, checkout posting, split-owner planning, and bundle residual allocation.
- Focused automated tests for rounding boundaries, tax-inclusive reconciliation, configuration isolation/disablement, manual overrides, bundle allocations, packed rows, persistence, and historical-load stability.
