## Why

The POS camera scanner currently auto-continues scanning after each barcode decode with only a brief 450ms cooldown. Cashiers have no moment to verify what was scanned — products are silently added to cart, and "not found" results flash by. This leads to missed scan errors, accidental duplicate additions, and low confidence in camera scanning. Additionally, when a cashier closes and reopens the camera scanner modal, the video feed often fails to display due to a race condition in the camera lifecycle.

## What Changes

- **Scan acknowledgment gate**: After every camera scan result (product found, serial found, not found, or error), the scanner pauses and displays the result in the existing status panel with a dismiss button. Scanning only resumes after the cashier taps "Lanjutkan Scan".
- **Enriched scan result messages**: Display the product name or serial number in the result message (e.g., `Produk "Semen Tiga Roda" telah ditambahkan`) instead of generic text.
- **Camera reopen fix**: Wait for the Bootstrap modal `shown.bs.modal` event before starting the camera, and properly await the html5-qrcode `stop()` promise during cleanup. This fixes the blank video feed on modal reopen.

## Capabilities

### New Capabilities
- `scan-acknowledge-gate`: Pause-on-scan behavior with a PAUSED state in the camera scanner state machine, dismiss button in the status panel, and enriched result messages for all scan outcomes.

### Modified Capabilities

## Impact

- `public/js/pos-camera-scanner.js` — New PAUSED state, dismiss button wiring, camera lifecycle fix (shown.bs.modal gate, async stop), state machine changes in handleDecodedValue/scheduleRearm
- `Modules/Pos/Resources/views/sell.blade.php` — Dismiss button HTML in scanner modal, enriched messages in executeScanResolve return values
- No backend/API changes. No database changes. No breaking changes.
