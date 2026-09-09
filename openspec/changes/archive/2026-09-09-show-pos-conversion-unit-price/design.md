## Context

The POS search and base-barcode flows already enrich eligible products through `PosUnitOptionsResolver`. Each conversion option contains its acting-business `price_for_setting`, but `renderUnitOptions()` in the sell page renders a price only for the base-unit card. Conversion prices are reference inputs to the existing packed-line calculation; the final cart amount can differ because customer tiers, cheapest-of packing, bundles, tax, rounding, and approved overrides remain authoritative.

The change must preserve business isolation and the current enabled-by-default behavior for legacy conversions without a business-scoped price row. It requires no database or checkout-persistence change.

## Goals / Non-Goals

**Goals:**

- Display the acting business's configured conversion price on selectable conversion cards.
- Make the label clear that this is the conversion-unit price, while leaving the cart as the source of truth for the final charge.
- Represent a missing conversion price explicitly without formatting `null` as zero.
- Cover the response and rendered markup with focused verification and provide human browser checks.

**Non-Goals:**

- Recalculate or predict the final cart total inside the unit picker.
- Change packed-line, tier, bundle, tax, rounding, override, or fallback calculations.
- Add schema, modify stored prices, or alter conversion eligibility.
- Add automated browser tests or run the full test suite.

## Decisions

### 1. Reuse the existing business-scoped conversion-price field

The picker SHALL render each conversion's existing `price_for_setting` value. `PosUnitOptionsResolver` remains the single source for business-scoped unit metadata, and its nullable value preserves the distinction between a configured zero-like value and a missing record/value.

Alternative: query conversion prices when opening the modal. This adds latency and creates a second source of truth for data already present in the search/scan response.

### 2. Present the value as a conversion-unit reference price

For a numeric value, the card will use the existing POS currency formatter and include the conversion unit label, for example `Harga konversi: Rp90.000 / Box`. The wording must not claim this is the final cart total because existing pricing can select a tier-derived amount, a cheaper packed amount, or a bundle price.

Alternative: calculate an "effective" amount in JavaScript. This would duplicate server pricing rules and could become stale or disagree with the cart, particularly after customer changes.

### 3. Render an explicit missing-price state

When `price_for_setting` is `null`, the conversion remains selectable under existing legacy eligibility rules, but the card will show a neutral message such as `Harga konversi belum tersedia`. It will not display `Rp0`, synthesize `factor × base price`, or change submission behavior.

Alternative: hide the price row for missing values. An explicit state is easier for cashiers to distinguish from a rendering failure.

### 4. Keep verification proportional to the presentation change

Focused tests will confirm that search/base-barcode unit options expose the correct business-scoped conversion price and preserve `null` when absent. A lightweight rendering assertion will cover the conversion price markup/formatting branch where practical. Human browser verification will cover the actual modal appearance for configured, missing, and disabled conversions.

## Risks / Trade-offs

- [Cashier interprets the reference price as the guaranteed final total] → Label it specifically as a conversion price and preserve the cart's authoritative calculated amount.
- [Missing values appear as a free conversion] → Branch explicitly on `null` and never pass it through numeric currency formatting.
- [Price from another business is exposed] → Continue resolving through the existing setting-scoped resolver and add focused isolation coverage.
- [Client rendering diverges from server pricing] → Do not reproduce pricing calculations in the picker.

## Migration Plan

No data migration is required. Deploy the response-contract verification and sell-page rendering together. Rollback consists of reverting the presentation change; existing cart and transaction data are unaffected. Run focused PHP tests, then have a human verify the modal in a browser before release acceptance.

## Open Questions

None.
