## Why

Businesses share product conversions but need independent control over using each conversion for sales and purchases. Product conversion price entry also currently strips decimal dots, risking a hundredfold price change when users enter raw decimals.

## What Changes

- Add Sales enabled and Purchase enabled controls to product conversion rows, scoped to the current business and defaulting to enabled.
- Preserve shared conversion creation and initial price seeding to all existing businesses; retain business-local price updates.
- Exclude sales-disabled conversions from Sales/POS pricing and reject their conversion barcode scans in Sales/POS, including alternate search entry paths.
- Exclude purchase-disabled conversions from new Purchase selections and validate eligibility on the server while preserving historical snapshots.
- Fix conversion price editing to accept raw dot decimals on focus and show `RP 1.234,56` on blur, retaining canonical submitted values and existing conversion-factor precision.
- Use focused automated verification only. Human operators perform browser testing; the implementation agent is not required to run browser tests or the full test suite.

## Capabilities

### New Capabilities

- `business-conversion-controls`: Independent per-business sales/purchase enablement, defaults, persistence, and Sales barcode enforcement.

### Modified Capabilities

- `pos-conversion-packing-pricing`: Only sales-enabled conversions qualify for new POS scans and packing-price capture; existing cached cart pricing remains frozen.
- `purchase-conversion-unit-entry`: Purchase-disabled conversions are ineligible for new selection in the acting business, without rewriting historical snapshots.

The price-input fix restores the existing `nominal-field-deterministic-rp-format` and `product-conversion-price-handling` contracts; those requirements do not change.

## Impact

Product conversion-price schema/model, product create/edit requests and persistence, Livewire UnitConfiguration and formatter, Sales cart pricing/scanning, POS scan/search and pricing-basis capture, and Purchase eligibility/validation/duplication paths. No new external dependencies. Existing global conversion metadata and unrelated inventory barcode workflows retain their behavior.
