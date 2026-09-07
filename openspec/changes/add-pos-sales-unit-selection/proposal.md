## Why

POS currently allows conversion quantity entry through conversion barcodes, but name search and base-unit barcodes offer no unit choice. Cashiers need the same factor-based addition through those entry paths, before choosing a bundle, while preserving existing base-unit accounting.

## What Changes

- Show a unit picker styled after the bundle picker on every name-result selection and base-barcode add when the current business has a sales-enabled conversion.
- Offer the base unit and sales-enabled conversion units; carry the selected conversion through bundle choice or explicit no-bundle continuation.
- Add the selected factor in base units; BOX = 12 followed by bundle selection adds 12 bundles and multiplies all component requirements accordingly.
- Keep conversion-barcode entry direct, serial-scan additions at one, and cart quantity controls in base units.
- Reject invalid or fractional conversion factors before mutation, recheck business enablement server-side, and protect pending selections against cancellation and duplicate activation.
- Verify with focused automated tests and a human browser checklist only.

## Capabilities

### New Capabilities
- `pos-sales-unit-selection`: Conditional unit picker, entry-source routing, selection lifecycle, and validated factor-based additions.

### Modified Capabilities
- `pos-bundle-selection-checkout`: Unit selection precedes bundle intent; selected conversion is carried into bundle additions and multiplied component requirements.

## Impact

Affected areas include POS search/scan response metadata, the sell page JavaScript and modal partials, cart conversion validation, and focused POS tests. Existing packing pricing, base-unit persistence, bundle composition, serial validation, business conversion controls, and merge rules remain authoritative. No database migration, new dependency, historical rewrite, full-suite test run, or automated browser test is planned.
