## Context

The POS camera scanner (`public/js/pos-camera-scanner.js`) uses ZXing-JS (`@zxing/browser` 0.20.0, bundled at `public/vendor/zxing/index.min.js`) for barcode/QR decoding. The scanner opens the camera successfully on mobile devices (confirmed via debug panel on Samsung A55 + Chrome) but ZXing's pure JavaScript canvas-based decode fails to resolve any barcode — even large, clearly visible ones that Google Lens reads instantly.

The existing scanner has a well-structured IIFE module with a state machine, session lifecycle, duplicate suppression, cooldown, debug panel, and integration with `window.executeScanResolve()`. The camera acquisition pipeline (getUserMedia, post-start constraints, zoom, focus) works correctly. Only the decode engine needs replacement.

Modern Android Chrome (83+) ships with the native `BarcodeDetector` API — the same hardware-accelerated engine behind Google Lens. This is available on the primary target device (Samsung A55).

## Goals / Non-Goals

**Goals:**
- Replace ZXing-JS decode engine with native `BarcodeDetector` API as the primary decode path
- Add `html5-qrcode` as a fallback decoder for browsers without native `BarcodeDetector` support
- Keep all existing scanner UI, state machine, session lifecycle, duplicate suppression, and debug infrastructure intact
- Report active decoder backend in debug panel for field diagnostics
- Remove ZXing-JS dependency (330KB bundle savings)

**Non-Goals:**
- Changing the scanner modal UI/UX, status feedback, or session behavior
- Modifying the camera acquisition pipeline (getUserMedia, constraints, zoom, focus)
- Changing the backend scan resolver or `executeScanResolve()` contract
- Supporting offline/PWA barcode scanning
- Adding new barcode formats beyond the existing allowlist

## Decisions

### Decision 1: Decoder Adapter Pattern with Runtime Backend Selection

**Choice:** Introduce a thin decoder adapter object inside `pos-camera-scanner.js` that exposes `start(videoElement, onDecode, onError)` and `stop()` methods. At initialization, the adapter checks `'BarcodeDetector' in window` and selects the backend.

**Alternatives considered:**
- *Use html5-qrcode for everything:* Simpler, but html5-qrcode's BarcodeDetector integration is indirect — it wraps its own camera lifecycle which conflicts with our existing pipeline. Using the native API directly gives us full control over frame timing and integrates cleanly with our existing video element.
- *Use only BarcodeDetector, no fallback:* Would work for Samsung A55 today, but locks out iOS Safari and older browsers. Adding html5-qrcode as fallback costs minimal complexity.

**Rationale:** The adapter keeps the scanner module's existing structure intact — `startDecoding()` calls `adapter.start()` instead of creating a `BrowserMultiFormatReader`. The rest of the state machine (READY, SUBMITTING, COOLDOWN, etc.) is untouched.

### Decision 2: Native BarcodeDetector Decode Loop Using requestAnimationFrame

**Choice:** For the native path, use a throttled `requestAnimationFrame` loop that calls `detector.detect(videoElement)` on each frame, throttled to ~100ms intervals (10 decode attempts/sec).

**Alternatives considered:**
- *requestAnimationFrame at full rate (~60fps):* Wastes CPU. Barcode detection doesn't need 60fps — the barcode isn't moving that fast.
- *setInterval at fixed rate:* Less battery-efficient than rAF, and rAF automatically pauses when the tab is backgrounded.
- *Canvas-based frame capture:* Unnecessary overhead — `BarcodeDetector.detect()` accepts a video element directly.

**Rationale:** 10 attempts/sec is responsive enough for scanning (typical scan takes <500ms) while being gentle on mobile CPU/battery. The rAF approach naturally pauses when the modal is hidden.

### Decision 3: html5-qrcode Fallback Uses Its Scanner Without Camera Lifecycle

**Choice:** For the fallback path, use `Html5Qrcode` (lower-level API, not the full scanner widget) in "scan from video element" mode, feeding it our existing video stream rather than letting it manage its own camera.

**Alternatives considered:**
- *Use Html5QrcodeScanner (full widget):* Would duplicate our entire camera lifecycle, modal, and UI. Conflicts with existing state machine.
- *Keep ZXing as fallback:* Could work but ZXing is the library that's failing in production. Using a different fallback avoids the same decode quality issues.

**Rationale:** `Html5Qrcode` can decode from a canvas/image source. We capture a frame from our existing video element into a canvas and pass it to `Html5Qrcode.scanFile()` or use its internal decode-from-canvas path. This preserves our camera pipeline while using html5-qrcode's decode engine (which itself uses ZXing internally but with better preprocessing).

### Decision 4: Format Mapping Between APIs

**Choice:** Maintain a single `FORMAT_ALLOWLIST` array and provide mapping functions for each backend:
- Native `BarcodeDetector` uses lowercase format strings: `'ean_13'`, `'qr_code'`, `'code_128'`, etc.
- `html5-qrcode` uses its own `Html5QrcodeSupportedFormats` enum.

**Rationale:** Centralizes format configuration. Adding or removing a format updates one list.

### Decision 5: Vendor html5-qrcode Bundle Like ZXing Was Vendored

**Choice:** Download the `html5-qrcode` minified bundle to `public/vendor/html5-qrcode/` and load it via `<script>` tag in `sell.blade.php`, same pattern as the current ZXing bundle.

**Alternatives considered:**
- *npm install + webpack/vite build:* The POS scanner JS is a standalone IIFE, not part of a build pipeline. Adding a build step for one dependency is overkill.
- *CDN:* Unreliable for POS terminals that may have restricted network. Previous ZXing CDN approach was already replaced with vendored bundle for this reason.

**Rationale:** Matches existing pattern. The fallback bundle only loads when BarcodeDetector is unavailable, so most Android users won't even download it.

## Risks / Trade-offs

- **[BarcodeDetector availability]** → Mitigated by html5-qrcode fallback. Runtime detection ensures the right backend is always selected. Debug panel shows which backend is active.
- **[html5-qrcode decode quality on iOS]** → html5-qrcode uses ZXing internally but with better frame preprocessing. If it still fails on some device, the adapter pattern makes it easy to swap in a different fallback later.
- **[BarcodeDetector format support varies by Chrome version]** → Use `BarcodeDetector.getSupportedFormats()` at init to verify. If a required format is missing, fall back to html5-qrcode for that session.
- **[html5-qrcode bundle size]** → ~150KB minified, smaller than ZXing (330KB). Net reduction.
- **[Conditional script loading complexity]** → Keep it simple: always include the html5-qrcode script tag. The native path won't use it, but it's cached after first load and avoids dynamic script injection complexity.

## Open Questions

- None blocking. All design decisions are grounded in the debug data and device testing results from the explore session.
