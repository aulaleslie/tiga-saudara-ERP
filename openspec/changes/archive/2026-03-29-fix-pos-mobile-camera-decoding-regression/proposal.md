## Why

The continuous-session refactor improved cashier flow but regressed real-device mobile decoding in the POS camera scanner: the camera opens, yet QR, EAN-13, and CODE-128 no longer resolve on phones that still scan correctly in dedicated barcode apps. Follow-up manual exploration on Samsung Galaxy A55 shows the problem is not only decode-loop behavior: the selected rear track reports as back-facing, but the live preview remains blurry and no code resolves. That points to a broader mobile capture regression where camera acquisition, post-start focus behavior, and decoder startup are no longer producing a scan-grade stream. This needs a focused ZXing-first recovery of the camera pipeline so mobile scanning works again without losing the continuous scanner session UX that tablet cashiers now depend on.

## What Changes

- Restore reliable mobile barcode and QR decoding in the POS camera scanner while keeping the continuous modal/session lifecycle and shared resolver flow introduced by the current scanner UX.
- Keep ZXing as the decoder, but recover the mobile camera pipeline around it: verify actual track capabilities/settings, apply focus-related constraints after stream start, and delay decode until the stream is in a known-good state.
- Replace the current callback-based continuous decode path with the older working decode approach if needed, but keep the surrounding session state machine, duplicate suppression, and re-arm semantics intact.
- Revisit camera constraints as an implementation detail rather than assuming `1280x720` alone restores focus; resolution, focus mode, zoom, and other post-start settings must be validated against actual track settings on-device.
- Expand the in-modal mobile debug helper so it exposes meaningful camera diagnostics without requiring browser devtools.
- Extend scanner coverage so the regression-prone decode configuration, modal debug surface, and continuous-session expectations remain guarded.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-scan-input-actions`: Refine POS camera scan requirements so the continuous mobile scanner remains open across scans while using a mobile-compatible ZXing decode path, verified post-start camera settings, and an optional in-session debug diagnostics panel that can explain mobile focus/lens failures.

## Impact

- Affected frontend logic: `public/js/pos-camera-scanner.js` decode setup, camera acquisition timing, post-start camera constraints, duplicate suppression integration, debug state tracking, and fatal/non-fatal scanner diagnostics.
- Affected POS view: `Modules/Pos/Resources/views/sell.blade.php` scanner modal markup and styling for the optional mobile debug helper.
- Affected tests: POS sell shell feature/UI coverage and any scanner-focused frontend or integration coverage that asserts scanner modal markup and session behavior.
- APIs/data model: no backend contract or schema changes; `window.executeScanResolve()` remains the authoritative submission path.
