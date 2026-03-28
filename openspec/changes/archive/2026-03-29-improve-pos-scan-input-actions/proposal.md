## Why

On tablet browsers, the POS scan field relies on Enter-only behavior that is not consistently triggered by mobile keyboard actions like Next, so product scan resolution can fail in real cashier flow. We need a deterministic helper action and a professional layout pattern now so scanning remains fast today and can accommodate a camera-scan action later without UI rework.

## What Changes

- Add an explicit helper action button for the POS shell scan input so manual/mobile users can trigger the same scan resolver without relying on keyboard Enter semantics.
- Refine the scan input action area into a tidy, professional action-rail layout with clear visual hierarchy between direct scan action and product search modal action.
- Reserve a stable layout slot for a future camera-scan action so adding tablet camera support does not require structural redesign.
- Align interaction behavior so keyboard Enter and helper-button trigger use one shared resolver flow and produce consistent status feedback.

## Capabilities

### New Capabilities
- `pos-scan-input-actions`: Defines deterministic scan-input triggering and professional action-rail layout requirements for POS shell, including forward-compatible placement for future camera scan action.

### Modified Capabilities
- None.

## Impact

- Affected frontend view and interaction wiring in `Modules/Pos/Resources/views/sell.blade.php`.
- Minor updates to POS shell interaction tests to cover helper-button parity with Enter path and layout/action visibility expectations.
- No backend API contract changes; existing `/pos/sell/search/resolve` endpoint remains unchanged.
