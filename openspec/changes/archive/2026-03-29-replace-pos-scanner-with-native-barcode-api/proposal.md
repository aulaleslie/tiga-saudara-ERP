## Why

The POS camera scanner opens correctly on mobile devices (Samsung A55, Android + Chrome) but ZXing-JS (`@zxing/browser` 0.20.0) fails to decode any barcode or QR code — even large, clearly visible ones that Google Lens and dedicated scanner apps read instantly. The root cause is that ZXing-JS uses pure JavaScript canvas-based pixel decoding which is too slow and unreliable on mobile, while modern Android Chrome ships with the native `BarcodeDetector` API that uses hardware-accelerated decoding. Replacing the decode engine with a native-first strategy will make scanning actually work on the devices cashiers use daily.

## What Changes

- Replace ZXing-JS decode engine with a layered decoder adapter: native `BarcodeDetector` API as primary path, `html5-qrcode` library as fallback for browsers without native support (e.g., iOS Safari in the future).
- Remove the bundled `vendor/zxing/index.min.js` (330KB) and `@zxing/browser` npm dependency.
- Add `html5-qrcode` as the fallback decoder dependency.
- Rewrite `startDecoding()` in `pos-camera-scanner.js` to use the decoder adapter instead of ZXing's `decodeFromVideoElement`.
- Keep all existing scanner infrastructure intact: modal UI, state machine, session lifecycle, duplicate suppression, cooldown, debug panel, `executeScanResolve()` integration, and status feedback.
- Enhance the debug panel to show which decoder backend is active (native vs fallback).

## Capabilities

### New Capabilities
- `pos-scanner-decoder-adapter`: Decoder adapter layer that selects native `BarcodeDetector` API when available and falls back to `html5-qrcode`, providing a unified decode interface to the scanner session.

### Modified Capabilities
- `pos-scan-input-actions`: Update camera decode requirements to specify the native-first decode strategy, supported format mapping for both backends, and decoder backend reporting in the debug diagnostics panel.

## Impact

- Affected frontend logic: `public/js/pos-camera-scanner.js` — decode initialization, frame decode loop, decoder cleanup, debug state reporting.
- Affected POS view: `Modules/Pos/Resources/views/sell.blade.php` — script includes (remove ZXing, add html5-qrcode fallback bundle).
- Removed dependency: `@zxing/browser` npm package, `public/vendor/zxing/index.min.js`.
- Added dependency: `html5-qrcode` npm package (or vendored bundle for fallback path).
- No backend, API, or data model changes.
- No changes to scanner modal HTML/CSS structure.
