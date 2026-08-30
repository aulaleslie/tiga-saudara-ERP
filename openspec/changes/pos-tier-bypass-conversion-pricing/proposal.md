## Why

POS packed-line pricing currently allows conversion/box pricing to compete with customer tier pricing. This produces the wrong commercial result for `WHOLESALER` and `RESELLER` customers, whose configured tier unit price must always be authoritative, and the POS cart currently hides fractional Rupiah amounts that reappear during checkout.

## What Changes

- Make `tier_1_price` the exclusive automatic unit-price source for `WHOLESALER` customers and `tier_2_price` the exclusive automatic unit-price source for `RESELLER` customers.
- Bypass conversion/box pricing for tier customers regardless of which candidate price is cheaper, while retaining conversion packing for customers without either tier.
- Recalculate existing packed lines immediately when the selected customer changes into or out of a tier.
- Preserve exact two-decimal tier-derived line totals, including totals such as `12 × 6583.33 = 78999.96`.
- Display POS cart and checkout-facing monetary totals with two decimal places so the customer-visible amount does not change between stages.

## Capabilities

### New Capabilities

- `pos-cart-money-display`: Defines consistent two-decimal monetary presentation across the active POS cart and checkout surfaces.

### Modified Capabilities

- `pos-conversion-packing-pricing`: Changes packed pricing so wholesaler and reseller tiers bypass conversion prices and use their respective base-unit tier price exclusively.
- `pos-line-unit-price-override`: Specifies that customer selection changes invalidate applied unit-price overrides and restore standard pricing.
- `pos-line-total-override`: Specifies that customer selection changes invalidate applied row-total overrides and restore standard pricing.

## Impact

- Affects POS packed pricing, customer-change and quantity-change repricing, cached pricing breakdown metadata, and cart total rendering.
- Expected implementation areas include `Modules/Pos/Services/PackedLinePricingService.php`, `Modules/Pos/Services/PosCartService.php`, `Modules/Pos/Resources/views/sell.blade.php`, and focused POS pricing/rendering tests.
- No schema changes or external dependencies are expected.
