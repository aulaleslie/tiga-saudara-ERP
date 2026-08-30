## Context

POS products with a configured unit conversion are represented as one base-unit cart line with a cached `pricing_basis`. `PackedLinePricingService` currently decomposes quantity into conversion groups and loose units, then applies the cheaper of the conversion price and the selected customer's tier-unit total. This makes conversion pricing authoritative for some `WHOLESALER` and `RESELLER` quantities, contrary to the commercial rule that tier customers never use conversion prices.

Packed totals already have an exact minor-unit `line_total`, while the blended `unit_price` is display-oriented. The active POS view converts the exact Rupiah totals to JavaScript numbers but formats them with zero fractional digits, so a value such as `78999.96` appears as `79000` until checkout exposes the decimals.

## Goals / Non-Goals

**Goals:**

- Make wholesaler and reseller tier-unit pricing authoritative for every automatic non-bundle packed line.
- Preserve conversion grouping and conversion pricing for non-tier customers.
- Invalidate applied manual/supervisor unit-price and row-total price overrides upon customer selection change, restoring standard/packed pricing for the new customer.
- Keep quantity and customer changes deterministic using the cached pricing basis.
- Preserve exact two-decimal line and cart totals independently of blended-unit-price rounding.
- Render active-cart and checkout-facing totals consistently with two decimal places.
- Cover the change with focused unit and POS feature tests only.

**Non-Goals:**

- Changing product price configuration, tier assignment, conversion records, or bundles.
- Allowing manual/supervisor price overrides to survive customer selection changes across customer tiers.
- Changing the existing fallback used when a tier price is zero or absent; any fallback remains a base-unit price and MUST NOT select a conversion price.
- Repricing historical completed transactions or changing persisted database schemas.
- Reformatting unrelated reporting and administrative POS pages.
- Planning or running the complete application test suite.

## Decisions

### Tier selection precedes packing selection

`PackedLinePricingService` will normalize the customer tier first. For `tier_1`/`WHOLESALER` and `tier_2`/`RESELLER`, it will calculate the authoritative line total as `quantity × resolved tier base-unit price`. The conversion factor may remain in the line for quantity identity and descriptive context, but the conversion price will not participate in monetary selection.

For customers without either tier, the existing box-group plus loose-remainder calculation remains unchanged. This keeps the behavior change limited to the two explicitly governed tiers.

Alternative considered: retain `min(box price, factor × tier price)`. This is rejected because it lets conversion pricing override the customer contract whenever the box is cheaper.

### Keep the exact line total as the arithmetic authority

Tier-derived totals will continue to be calculated in integer minor units from the cached two-decimal tier price. The exact `line_total_minor`/packed line total will feed `PosCartTotalsCalculator`; `unit_price` remains a display value and will not be multiplied back to reconstruct the total.

This preserves `12 × 6583.33 = 78999.96` exactly and avoids drift for packed totals that do not divide evenly. Replacing the existing money representation with floating-point arithmetic or a new money library is out of scope.

### Reuse the existing cached pricing basis and repricing triggers

Quantity updates and customer selection changes already invoke packed repricing with cached `pricing_basis`. The implementation will change the pricing policy centrally rather than adding database queries or parallel controller logic. Breakdown metadata will identify that tier pricing was applied and must not claim that a box price was charged.

Alternative considered: convert tier-customer rows from `PACKED` to ordinary `TIER` rows. This is rejected because it risks breaking merge keys, conversion identity, draft reloads, receipts, and established packed-line total authority.

### Use one two-decimal formatter for the active sell flow

The sell view's monetary formatter(s) will use the configured Indonesian IDR presentation with exactly two fraction digits for cart totals and checkout-facing summaries. All uses of the active sell-flow formatter will receive the same numeric snapshot amount; formatting will not round or mutate the underlying value.

Alternative considered: display decimals only when non-zero. This is rejected because fixed two-decimal output makes cart and checkout presentation predictable and exposes precision before payment.

## Risks / Trade-offs

- [Existing tests encode cheapest-of pricing for tier customers] → Update only the focused packed-pricing and customer-repricing expectations, while retaining non-tier packing regression cases.
- [Receipt breakdown may describe a box even though its price was bypassed] → Ensure tier breakdown metadata represents base-unit tier pricing and add a focused assertion where receipt/cart rendering consumes it.
- [A missing tier price could accidentally fall back to a conversion price] → Keep fallback resolution confined to base-unit sale pricing and explicitly test that conversion price is still bypassed.
- [Multiple JavaScript formatters can diverge] → Consolidate or align the active sell-flow formatters and add a focused rendered-view or source-level assertion for two fraction digits.
- [Historical cart snapshots may lack newer breakdown metadata] → Preserve the current snapshot compatibility path and change only recalculation of mutable carts.

## Migration Plan

1. Deploy the service and sell-view changes without a database migration.
2. Existing mutable carts adopt the new result on their next quantity or customer repricing event; new carts use it immediately.
3. Verify with focused packed-pricing unit tests and POS customer-change/cart-rendering feature tests.
4. Roll back the application change if necessary; no stored data migration needs reversal.

## Open Questions

None. The business rule applies equally to `WHOLESALER` and `RESELLER`, and verification is intentionally limited to focused tests.
