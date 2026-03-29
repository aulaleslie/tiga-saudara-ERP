## Why

The continuous-session refactor improved cashier flow but regressed real-device mobile decoding in the POS camera scanner: the camera opens, yet QR, EAN-13, and CODE-128 no longer resolve on phones that still scan correctly in dedicated barcode apps. This needs a focused rollback of the decode path so mobile scanning works again without losing the continuous scanner session UX that tablet cashiers now depend on.

## What Changes

- Restore reliable mobile barcode and QR decoding in the POS camera scanner while keeping the continuous modal/session lifecycle and shared resolver flow introduced by the current scanner UX.
- Replace the current callback-based continuous decode path with the older working decode approach if needed, but keep the surrounding session state machine, duplicate suppression, and re-arm semantics intact.
- Remove the `ASSUME_GS1` decode hint and lower requested camera constraints from `1920x1080` back to `1280x720` to match the older working profile.
- Add an optional in-modal mobile debug helper that exposes scanner runtime state without requiring browser devtools.
- Extend scanner coverage so the regression-prone decode configuration, modal debug surface, and continuous-session expectations remain guarded.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-scan-input-actions`: Refine POS camera scan requirements so the continuous mobile scanner remains open across scans while using a mobile-compatible decode path, tablet-safe camera constraints, and an optional in-session debug diagnostics panel.

## Impact

- Affected frontend logic: `public/js/pos-camera-scanner.js` decode setup, camera constraints, duplicate suppression integration, debug state tracking, and fatal/non-fatal scanner diagnostics.
- Affected POS view: `Modules/Pos/Resources/views/sell.blade.php` scanner modal markup and styling for the optional mobile debug helper.
- Affected tests: POS sell shell feature/UI coverage and any scanner-focused frontend or integration coverage that asserts scanner modal markup and session behavior.
- APIs/data model: no backend contract or schema changes; `window.executeScanResolve()` remains the authoritative submission path.
