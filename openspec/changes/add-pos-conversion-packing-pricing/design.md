## Context

POS pricing today has two entirely separate paths in `PosCartService::addLine`: a conversion line takes `ProductUnitConversionPrice.price` as its per-box `unit_price` and freezes it; a base line takes the tier-resolved `sale_price`. `updateLine` changes quantity but never recomputes `unit_price`, so the total is wrong the moment quantity changes. `updateCustomerSelection` reprices conversion lines through `resolveLinePrice`, which ignores the conversion and returns the base/tier price — silently mispricing a box as one base unit. The totals calculator (`PosCartTotalsCalculator`) is a pure `qty × unit_price` engine working in integer minor units; checkout preflight does not re-derive prices and treats the cart snapshot totals as authoritative.

Reference domain example: product Kertas A4, base unit REAM, box conversion factor 5, box price 210000. Non-tier base 45000, reseller base 42000. Target: quantity 6 → 255000 (non-tier) and 252000 (reseller).

## Goals / Non-Goals

**Goals:**
- Quantity always in base units; box scan seeds quantity with the conversion factor.
- Reprice on every quantity and customer-tier change (fixes the core bug).
- Respect customer tier by always charging the cheaper of box vs tier base-unit pricing, per box group.
- Zero additional DB queries on quantity/customer updates; pricing inputs cached once at add time.
- Totals tie out to the rupiah despite a blended per-unit price.

**Non-Goals:**
- No schema change: no tier columns on `product_unit_conversion_prices`; box tier price is derived.
- No mid-cart price refresh; prices frozen at scan time.
- No change to bundle pricing or to the checkout/preflight price-derivation model.
- No change to how returns are priced (returns are handled separately and do not depend on the blended sale price).

## Decisions

### D1: Base-unit quantity with cached `pricing_basis`
Store a per-line `pricing_basis` = { factor, box_price, base_price, tier_1_price, tier_2_price, tax_id, tax_name, tax_rate } populated once at add/scan time (DB touched once). Quantity/customer updates re-pack from this cache with no queries.
- Alternative (re-query on every update): rejected for performance — a customer switch reprices every line and would issue 3 queries per line per keystroke.

### D2: Per-group independent packing
`box_count = floor(qty/factor)`, `remainder = qty % factor`. Each box group charges `min(box_price, factor × tier_base_price)`; remainder charges `remainder × tier_base_price`. Because every group covers exactly `factor` base units, the per-group decision is independent — a single comparison per group suffices.
- Alternative (all-or-nothing per line): rejected — does not match the desired remainder behavior in the domain example.

### D3: Derived box tier price (no new columns)
Tier only affects the loose/tier base price; the box is simply another candidate in the `min()`. Reseller box benefit falls out of `factor × tier_base` vs `box_price`. Works for the example (5×42000 = 210000 = box price).
- Alternative (tier columns on conversion price): rejected as unnecessary schema + UI change.

### D4: Blended line with authoritative `line_total`
Store one line: `line_total` (authoritative, integer minor units from packing) and `unit_price = line_total / qty` (display-only). Extend `PosCartTotalsCalculator` so a line carrying an authoritative line total uses it for `_line_gross_minor` instead of `qty × unit_price`; all downstream discount/proration/tax math keys off the corrected gross unchanged. Non-packed lines keep the exact legacy `qty × unit_price` path (no regression to base/tier/override/bundle lines).
- Alternative (snap blended price so `qty × price == line_total`): impossible at 2 decimals in general (e.g. 3 × x = 100000). Rejected.
- Alternative (accept rounding drift): rejected — a POS must not display 254999 for a 255000 sale.

### D5: Merge key excludes blended price; re-pack total quantity
Merge key = product + tax + conversion + tier + PACKED marker (blended price excluded), so repeated box scans coalesce. On coalesce/quantity change, re-pack the line's **total** quantity from scratch (never price an increment in isolation, which would break the per-group + remainder math). `PosTransactionSnapshotMapper::computeMergeKey` must mirror this shape for reload parity.

### D6: Unified packed path
Any stock-managed product line whose product has a box conversion carries `pricing_basis` and flows through the packing engine, regardless of entry path. Base vs conversion pricing collapse into one packed path; bundles remain a separate branch.

## Risks / Trade-offs

- **Stale price frozen at scan time** → Mitigation: matches existing conversion-line semantics (today's code already caches `unit_price` at add time); documented as intended. No refresh hatch by decision.
- **Totals calculator override could regress non-packed lines** → Mitigation: override is opt-in per line (only present on packed lines); legacy `qty × unit_price` path is unchanged and covered by existing `PosCartTotalsCalculatorTest`.
- **Merge-key drift between cart and snapshot mapper** → Mitigation: extract a single shared merge-key helper (or mirror exactly) and add a reload-parity regression test.
- **Blended `unit_price` is not a price-list number** → Accepted: returns are priced separately and do not depend on the sale blended price (per product owner decision).
- **Discounts/tax on a blended line** → Mitigation: because the authoritative `line_total` seeds `_line_gross_minor`, percentage line discounts, bill-discount proration, and PKP tax extraction all operate on the correct base with no special casing.
