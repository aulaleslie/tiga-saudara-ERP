## Why

Cashiers who type serial numbers manually in the POS serial modal naturally click `Selesai`, expecting the serial to be submitted. Today submission only happens on Enter/scanner input, so typed serials are silently not added when users click close.

## What Changes

- Add an explicit manual submit action in the serial modal (for example `Masukkan`) that appends the typed serial using the same append flow as Enter/scanner input.
- Keep Enter/scanner burst workflow unchanged so high-speed scanning remains efficient.
- Clarify footer actions so close behavior is distinct from submit behavior (for example rename `Selesai` to a close-oriented label such as `Tutup`).
- Add in-modal guidance that serial input can be submitted via Enter or the manual submit button.
- Keep backend contracts unchanged (`/pos/sell/cart/lines/{lineId}/serials/append` and existing validation/error responses).

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-serial-modal-gui`: Extend serial modal requirements to require an explicit click-based append control in addition to Enter/scanner submission, and require clear close-vs-submit affordance.

## Impact

- Affected frontend: `Modules/Pos/Resources/views/sell.blade.php` modal markup and serial input event wiring.
- Affected tests: POS serial modal interaction coverage (manual submit click, Enter parity, close action semantics).
- APIs/data model: no new endpoints and no schema/migration changes.
