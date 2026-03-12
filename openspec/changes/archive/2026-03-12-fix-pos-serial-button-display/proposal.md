## Why

The serial control on POS sell rows can appear blank and key serial-modal interactions are partially broken, which blocks cashiers from reliably adding and removing serial assignments during a live transaction. This issue should be fixed now because serial-required sales depend on this flow and UI failures increase checkout errors and operator delay.

## What Changes

- Replace the serial action button rendering so it remains visibly identifiable without Font Awesome availability (Bootstrap Icons-compatible and/or text fallback).
- Correct cart-row product name lookup used when opening the serial modal so modal context always shows the selected product.
- Fix serial remove interaction inside the serial modal so removing a serial from modal chips always triggers the expected API call.
- Add targeted regression coverage for serial button visibility and modal serial interactions in the POS sell UI behavior.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-serial-modal-gui`: Extend requirements to guarantee visible serial action affordance and functional modal-level serial removal using the active cart line context.

## Impact

- Affected UI view/script: `Modules/Pos/Resources/views/sell.blade.php`.
- Potential shared styling/icon dependency considerations in `resources/views/includes/main-css.blade.php`.
- POS serial UX test/manual regression checklist must be updated for button visibility, modal product name, and modal remove behavior.
