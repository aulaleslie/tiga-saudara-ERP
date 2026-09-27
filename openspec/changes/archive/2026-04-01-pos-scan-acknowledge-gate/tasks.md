## 1. Camera Reopen Fix

- [x] 1.1 In `pos-camera-scanner.js` `openScanner()`, move `decoderAdapter.selectBackend()` and camera startup into a `$(modalElement).one('shown.bs.modal', ...)` callback so the camera pipeline only starts after the modal is fully visible
- [x] 1.2 In `DecoderAdapter.stop()`, return the `html5QrcodeInstance.stop()` promise instead of fire-and-forget, so callers can await cleanup
- [x] 1.3 In `stopSession()`, await the `DecoderAdapter.stop()` promise (or guard reopen against in-progress cleanup) to prevent resource contention on rapid close/reopen

## 2. PAUSED State & Dismiss Button

- [x] 2.1 Add `PAUSED: 'paused'` to the `States` enum in `pos-camera-scanner.js`
- [x] 2.2 Add a dismiss button (`#pos-camera-scanner-dismiss`, hidden by default) inside the `pos-camera-scanner-session-status` panel in `sell.blade.php`
- [x] 2.3 In `initialize()`, wire the dismiss button click to hide the button and call `scheduleRearm()`
- [x] 2.4 In `handleDecodedValue()` `.then()` handler, set `state = States.PAUSED` and show the dismiss button instead of falling through to `scheduleRearm()`
- [x] 2.5 In `handleDecodedValue()` `.finally()` handler, only call `scheduleRearm()` when `state !== States.PAUSED` (error paths still auto-rearm)
- [x] 2.6 In `armScanner()`, ensure the dismiss button is hidden when transitioning to READY

## 3. Enriched Scan Result Messages

- [x] 3.1 In `sell.blade.php` `executeScanResolve()`, change the `product_exact` return message to `Produk "<product_name>" telah ditambahkan` using `response.product.product_name`
- [x] 3.2 In `sell.blade.php` `executeScanResolve()`, change the `serial_exact` return message to `Serial "<serial_number>" telah ditambahkan` using `response.serial.serial_number`
- [x] 3.3 In `sell.blade.php` `executeScanResolve()`, change the `not_found` return message to `Kode "<query>" tidak ditemukan` including the scanned query value

## 4. Verification

- [x] 4.1 Test camera scanner: scan a known product barcode — verify status panel shows product name, dismiss button appears, scanner does not auto-continue
- [x] 4.2 Test camera scanner: scan an unknown barcode — verify "not found" message with code shown, dismiss button appears
- [x] 4.3 Test dismiss button: click "Lanjutkan Scan" — verify scanner re-arms and resumes decoding
- [x] 4.4 Test modal reopen: close camera modal and reopen — verify video feed displays correctly
- [x] 4.5 Test manual input: type barcode in search field + Enter — verify product adds immediately without pause dialog
