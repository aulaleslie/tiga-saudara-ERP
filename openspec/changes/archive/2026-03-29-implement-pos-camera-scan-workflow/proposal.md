## Why

POS scan input currently supports manual entry and hardware scanner Enter flow, but the camera slot is still placeholder-only. Cashiers on laptops, tablets, and phones need one deterministic camera trigger that decodes a code and reuses the same resolver flow without introducing a second scan contract.

## What Changes

- Activate the existing camera action in POS sell scan rail so users can start camera scanning directly from `/pos/sell`.
- Add a single-scan camera workflow that prefers rear camera on supported mobile/tablet devices and falls back to available camera on laptops/desktops.
- Decode common 1D and 2D formats from live camera feed and submit raw decoded value through the existing POS scan resolver flow.
- Mirror decoded value into the existing barcode/serial input before resolver call so users can review and manually edit value when needed.
- Close scanner modal after first decode attempt (success or not-found) and stop media stream deterministically.
- Preserve existing resolver API guard (`q` max length 255) and handle over-limit decoded values with client-side warning/no resolver call.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-scan-input-actions`: Extend scan action rail requirements from reserved camera slot to active camera scanning behavior, including decoded-value input mirroring, single-scan close policy, and not-found review semantics.

## Impact

- Affected frontend POS shell view and interaction logic in `Modules/Pos/Resources/views/sell.blade.php`.
- Adds frontend camera-scanning dependency for browser decoding support.
- Updates POS sell shell UI tests and scan-action behavior coverage.
- No backend endpoint shape changes; existing `/pos/sell/search/resolve` contract remains in use.
