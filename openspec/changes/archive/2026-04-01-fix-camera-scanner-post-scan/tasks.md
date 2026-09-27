## 1. Keep Decoder Running After Scan

- [x] 1.1 Remove `restartDecoding()` call from `armScanner()` in `pos-camera-scanner.js` — the decoder already keeps running during SUBMITTING/COOLDOWN and the existing guards prevent duplicate processing
- [x] 1.2 Verify `restartDecoding()` function is still used by `retryScanning()` — do NOT delete it, only remove the call from `armScanner()`

## 2. Display Scanned Code in Post-Resolve Messages

- [x] 2.1 In `handleDecodedValue()`, modify the `.then()` handler to include `lastAcceptedCode` in the detail text for all outcomes (product_exact, serial_exact, not_found, resolver_error)
- [x] 2.2 In `handleDecodedValue()`, modify the `.catch()` handler to include `lastAcceptedCode` in the error detail text

## 3. Preserve Scan Result Until Next Scan

- [x] 3.1 Add a `hasScannedOnce` flag (initialized to `false`) to the scanner module state — set it to `true` when `handleDecodedValue()` begins processing a new code
- [x] 3.2 In `armScanner()`, check `hasScannedOnce` — if true, skip `setSessionMessage(Messages.READY)` so the last scan result remains visible; if false (initial arm or retry), show the Ready message as before
- [x] 3.3 Reset `hasScannedOnce` to `false` in `stopSession()` and in `retryScanning()` so re-opening or retrying shows the Ready message

## 4. Verification

- [x] 4.1 Manual test: Open scanner → scan a product barcode → confirm scanned code and "Produk ditambahkan" visible in dialog → confirm camera stays active → scan a second different barcode → confirm it processes correctly
- [x] 4.2 Manual test: Scan a barcode not in the system → confirm scanned code and "Kode tidak ditemukan" visible in dialog → confirm camera stays active for next scan
- [x] 4.3 Manual test: Close scanner → reopen → confirm "Ready" message shows on initial open
- [x] 4.4 Manual test (fallback): Force fallback backend (use browser without BarcodeDetector) → repeat tests 4.1-4.3 to verify html5-qrcode path works correctly
