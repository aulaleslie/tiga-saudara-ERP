## 1. Tier Pricing Policy

- [x] 1.1 Update `PackedLinePricingService` so normalized wholesaler and reseller tiers calculate the authoritative line total exclusively as base-unit quantity multiplied by the resolved tier unit price, bypassing conversion prices.
- [x] 1.2 Preserve the existing non-tier box-group and loose-remainder calculation and ensure tier-price fallback remains base-unit pricing rather than conversion pricing.
- [x] 1.3 Update packed pricing breakdown metadata so tier-priced rows do not report that a conversion/box price was applied.

## 2. Cart Repricing and Precision

- [x] 2.1 Verify and adjust quantity-change and customer-change paths in `PosCartService` to reuse cached pricing inputs while applying the new tier-exclusive policy.
- [x] 2.2 Preserve the exact minor-unit tier line total through `PosCartTotalsCalculator` so `12 × 6583.33` contributes `78999.96` without reconstruction from a rounded display unit price.
- [x] 2.3 Preserve packed-line merge, override, bundle, and mutable historical snapshot behavior while switching between non-tier, wholesaler, and reseller customers.

## 3. POS Monetary Presentation

- [x] 3.1 Consolidate or align the active `sell.blade.php` currency formatters to render exactly two decimal places using Indonesian IDR separators.
- [x] 3.2 Ensure cart subtotal, grand total, payment summary, and checkout receipt total use the same formatter without changing the numeric snapshot value submitted to checkout.

## 4. Focused Verification

- [x] 4.1 Update focused `PackedLinePricingServiceTest` cases for wholesaler and reseller conversion bypass, including a conversion price cheaper than a tier total.
- [x] 4.2 Add a focused POS feature test for the sequence `8000 × 1`, quantity `12` at conversion total `85000`, then reseller selection producing exactly `78999.96`.
- [x] 4.3 Add focused customer-change coverage for wholesaler bypass, clearing a tier customer back to non-tier packing, and tier-price fallback that still bypasses conversion pricing.
- [x] 4.4 Add focused sell-view formatting coverage proving `78999.96` remains visible with two decimal places across cart and checkout-facing summaries.
- [x] 4.5 Run only the affected packed-pricing unit test and focused POS feature/view tests; do not run or plan the full application suite for this change.
