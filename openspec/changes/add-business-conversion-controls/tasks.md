## 1. Business conversion settings

- [x] 1.1 Add an additive migration for sales_enabled and purchase_enabled on product_unit_conversion_prices, both non-null and default true; add model casts and reusable business-aware eligibility accessors with enabled fallback for missing rows.
- [x] 1.2 Extend product create/edit request validation, form hydration, and persistence for explicit boolean controls. Preserve all-business initial price seeding, apply explicit creation choices only to the acting business, and preserve existing flags on omitted-field/price-only writes and backfills.
- [x] 1.3 Add Sales enabled and Purchase enabled columns to UnitConfiguration with enabled defaults for new rows; retain false values and conversion identity across row changes, Livewire rerenders, and validation errors.

## 2. Sales and POS enforcement

- [x] 2.1 Trace Sales/POS conversion pricing, barcode scan, exact-search, and cart-add routes; filter Sales conversion-price candidates by the acting business's sales flag while preserving normal-price fallback.
- [x] 2.2 Reject disabled conversion barcodes before Sales/POS cart mutation, including alternate exact-search fallback and scans that would increment an existing line; return an actionable message.
- [x] 2.3 Filter new POS pricing-basis conversion candidates by sales eligibility for both barcode and ordinary product entry; preserve existing cached pricing, enabled packing arithmetic, and zero-query repricing behavior.

## 3. Purchase eligibility

- [x] 3.1 Extend centralized purchase conversion eligibility with the acting business's purchase flag and load the needed business records without per-row queries; use it for product unit options and new/switched selection validation.
- [x] 3.2 Apply eligibility to save/normalization and duplication intent, preserve canonical base-unit duplication fallback, and prevent historical-option fallback from authorizing newly selected disabled conversions. Retain persisted snapshot and receiving behavior.

## 4. Conversion price entry

- [x] 4.1 Fix UnitConfiguration price editing so focus exposes canonical dot decimals, input preserves decimal dots, and blur displays RP grouping with two decimals; keep canonical hidden values authoritative during submit and repeated blur/focus events.
- [x] 4.2 Preserve formatter behavior across dynamic rows, Livewire rerenders, and validation round-trips; confirm create/update request normalization preserves raw 1234.56 and existing formatted-request compatibility. Retain conversion-factor precision.

## 5. Focused verification and human handoff

- [x] 5.1 Add or extend focused Product checks for enabled migration/defaults, initial all-business seeding, independent flags, two-business isolation, and preservation of disabled values during price-only updates.
- [x] 5.2 Add or extend focused Sales/POS checks for disabled candidate exclusion, scan/search rejection without cart mutation, another business remaining enabled, and existing cached-basis preservation.
- [x] 5.3 Add or extend focused Purchase checks for disabled option exclusion, server rejection of new disabled selections, duplication fallback, independent sales/purchase flags, and persisted snapshot preservation.
- [x] 5.4 Run focused decimal normalization/formatter regression checks using existing non-browser tooling where available for 1234.56, 50000, repeated formatting, and submit while focused. Run only relevant PHP test filters and report results; do not run the full suite or agent browser tests.
- [x] 5.5 Provide a short human browser acceptance checklist for business switching, both enable controls, blocked sales conversion scans, purchase options, and price focus/blur plus dynamic-row/validation behavior. Human browser execution is not a prerequisite for implementation-agent completion.
