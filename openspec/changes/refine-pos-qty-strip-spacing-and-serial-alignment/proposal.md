## Why

The POS qty column still feels visually uneven: minus/input/plus spacing is wider than needed, button styling is inconsistent, and serial-row controls are not stacked/aligned in a compact center-focused structure. This slows cashier scanning and increases misclick risk during rapid cart edits.

## What Changes

- Tighten qty-strip spacing so `[-][input][+]` renders as a compact control group for both serial and non-serial rows.
- Standardize spinner button appearance in the qty column:
  - minus uses `outline-danger` with fill on hover,
  - plus uses `outline-primary` with fill on hover,
  - rectangular borders and existing border radius are preserved.
- Refine serial-required qty cell composition so controls are center-aligned in one stacked structure:
  - top row: `[-][input][+]`,
  - secondary row: serial action control,
  - tertiary row: serial pills/chips.
- Keep existing approval and permission behavior unchanged (`Periksa` / approved flow, privileged vs non-privileged semantics), while applying the visual layout contract consistently.
- Add targeted regression checks for qty-column layout consistency across serial/non-serial and permission states.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-supervised-cart-actions`: qty-column controls for supervised and direct actions must use a compact, consistent visual stepper contract with deterministic control-slot behavior.
- `pos-serial-modal-gui`: serial-required cart rows must present serial management affordances and chips in a center-aligned stacked qty-cell layout.

## Impact

- Affected UI: `Modules/Pos/Resources/views/sell.blade.php` (qty control markup, class usage, and inline/cart-row layout composition).
- Affected styling: qty-control and serial-row CSS rules in the POS sell view.
- Affected tests: POS cart rendering and supervised qty-action regression coverage for serial and non-serial rows.
- No backend endpoint, token, or approval lifecycle contract changes.
