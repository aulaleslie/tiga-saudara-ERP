## 1. Dependency Setup

- [ ] 1.1 Download html5-qrcode minified bundle and place in `public/vendor/html5-qrcode/html5-qrcode.min.js`
- [ ] 1.2 Update `sell.blade.php` script includes: remove ZXing script tag, add html5-qrcode script tag
- [ ] 1.3 Remove `public/vendor/zxing/index.min.js` and the `public/vendor/zxing/` directory
- [ ] 1.4 Remove `@zxing/browser` from `package.json` dependencies

## 2. Decoder Adapter Implementation

- [ ] 2.1 Create decoder adapter object inside `pos-camera-scanner.js` with `start(videoElement, onDecode, onError)` and `stop()` interface
- [ ] 2.2 Implement runtime backend selection: check `'BarcodeDetector' in window`, verify required formats via `getSupportedFormats()`, select native or fallback
- [ ] 2.3 Implement format mapping: single `FORMAT_ALLOWLIST` → native lowercase strings (`'ean_13'`, `'qr_code'`, etc.) and html5-qrcode `Html5QrcodeSupportedFormats` enum values
- [ ] 2.4 Store selected backend name (`'BarcodeDetector (native)'` or `'html5-qrcode (fallback)'`) for debug panel reporting

## 3. Native BarcodeDetector Decode Path

- [ ] 3.1 Implement native decode loop using `requestAnimationFrame` with 100ms throttle
- [ ] 3.2 Call `detector.detect(videoElement)` and invoke `onDecode` with `barcode.rawValue` for first result
- [ ] 3.3 Handle frame misses silently (empty results array) and propagate only fatal errors via `onError`
- [ ] 3.4 Implement `stop()` to cancel pending animation frame and release detector reference

## 4. html5-qrcode Fallback Decode Path

- [ ] 4.1 Implement fallback decode loop using `requestAnimationFrame` with 100ms throttle
- [ ] 4.2 Capture video frame to offscreen canvas, pass to html5-qrcode for decode
- [ ] 4.3 Invoke `onDecode` with decoded text on success, silently continue on frame miss
- [ ] 4.4 Implement `stop()` to cancel pending animation frame and clean up canvas/decoder

## 5. Scanner Integration

- [ ] 5.1 Replace `startDecoding()` body: remove all ZXing initialization, call `adapter.start()` with `handleDecodedValue` as `onDecode` callback
- [ ] 5.2 Replace decoder cleanup in `stopSession()` and `stopDecoding()`: call `adapter.stop()` instead of ZXing `reset()`
- [ ] 5.3 Remove all ZXing-specific imports and references (`BrowserMultiFormatReader`, `FormatException`, `NotFoundException`, `ChecksumException`, `DecodeHintType`, `BarcodeFormat`)
- [ ] 5.4 Verify existing state machine transitions (READY → SUBMITTING → COOLDOWN → READY) still work with adapter callbacks

## 6. Debug Panel Enhancement

- [ ] 6.1 Add `decoderBackend` field to `debugState` object
- [ ] 6.2 Set `decoderBackend` value during adapter initialization (before decode starts)
- [ ] 6.3 Render decoder backend name in debug panel HTML output (e.g., "Decoder: BarcodeDetector (native)")
- [ ] 6.4 Verify debug panel still shows all existing fields: state, stream, video dimensions, track label, last decoded text/format, frame miss count, error info, resolver in-flight status

## 7. Cleanup and Verification

- [ ] 7.1 Remove unused ZXing-related constants and variables (`decoderInstance`, ZXing-specific error class references)
- [ ] 7.2 Test on Samsung A55 (Android Chrome): verify native BarcodeDetector path decodes EAN-13, CODE-128, and QR codes
- [ ] 7.3 Test debug panel with `?scanner_debug=1`: verify decoder backend shows "BarcodeDetector (native)" and all diagnostic fields render correctly
- [ ] 7.4 Verify session lifecycle: modal stays open across scans, duplicate suppression works, cooldown re-arms correctly
- [ ] 7.5 Verify fallback path works by testing in a browser without BarcodeDetector (or by temporarily disabling native detection)
