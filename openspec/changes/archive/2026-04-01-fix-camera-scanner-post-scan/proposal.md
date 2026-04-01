## Why

The POS camera scanner has two UX bugs after a successful barcode scan: (1) the scanned code value disappears from the scan dialog almost immediately — the post-resolve message overwrites it with a generic result, then `armScanner()` replaces everything with "Scanner siap untuk item berikutnya" after 450ms; (2) the camera stops working after the first scan because `restartDecoding()` destroys the active decoder and the fallback (html5-qrcode) path cannot restart it, while the native path unnecessarily destroys and recreates the BarcodeDetector instance and rAF loop. This makes continuous scanning impossible and forces the cashier to close and reopen the scanner for every item.

## What Changes

- **Keep decoder running after scan**: Remove the stop/restart cycle in `armScanner()` → `restartDecoding()`. The decoder (both native rAF loop and html5-qrcode) is already running during SUBMITTING/COOLDOWN states — the `submissionInFlight` and state guards already prevent duplicate processing.
- **Display scanned code in post-resolve messages**: Include `lastAcceptedCode` in all session status messages shown after the resolver completes (product_exact, serial_exact, not_found, and error outcomes).
- **Preserve scan result until next scan**: Stop `armScanner()` from unconditionally overwriting the session message with the generic "Ready" message after a scan. The last scan result should remain visible until the next barcode is decoded.

## Capabilities

### New Capabilities
- `camera-continuous-scan`: Camera decoder stays active after a successful scan so the cashier can immediately scan the next item without closing/reopening the scanner modal.
- `camera-scan-result-display`: After each scan, the scan dialog shows the scanned barcode value and the resolve outcome (product added, serial added, or not found) persistently until the next scan.

### Modified Capabilities

## Impact

- **Code**: `public/js/pos-camera-scanner.js` — `handleDecodedValue()`, `armScanner()`, `restartDecoding()`, `scheduleRearm()`, and the `Messages` object.
- **No API changes**: The resolver endpoint and its contract remain unchanged.
- **No new dependencies**: Uses existing session status UI elements in the scanner modal.
- **Risk**: Removing the stop/restart cycle means the decoder loop keeps calling `handleDecodedValue()` during COOLDOWN — already guarded by `submissionInFlight` and `state === COOLDOWN` checks, plus `SAME_CODE_SUPPRESSION_MS` prevents re-processing the same barcode.
