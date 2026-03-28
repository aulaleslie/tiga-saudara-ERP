# Manual Validation Guide for POS Camera Scanner Fix

## Overview
This document provides step-by-step instructions for manually validating the POS camera scanner improvements on both laptop and mobile devices.

## Prerequisites
- POS system running with the updated code
- Web browser with camera access enabled
- Test products/barcodes available for scanning
- Access to browser console (F12 → Console tab)

## Test Scenarios

### Scenario 1: Camera Open Idle (No Premature Error)
**Expected Behavior:** Camera opens, shows "Tunjukkan barcode ke kamera" message, NO error message displayed.

**Steps:**
1. Open POS sell interface
2. Click camera button (📷 icon in scan action rail)
3. Camera modal opens
4. Observe status text shows "Membuka kamera..." then "Tunjukkan barcode ke kamera"
5. **Verify:** No "Gagal memproses barcode" error appears
6. Keep camera idle for 3-5 seconds without scanning anything
7. **Verify:** Status remains "Tunjukkan barcode ke kamera", no premature errors

**Validation on Mobile:** Repeat with both rear and front camera (should prefer rear camera)

---

### Scenario 2: Successful Barcode Scan
**Expected Behavior:** Barcode is decoded, product is resolved and added to cart, scanner closes automatically.

**Steps:**
1. Open POS camera scanner (as above)
2. Point camera at a valid product barcode
3. Wait for barcode to be scanned
4. **Verify:** Status changes to "Memindai..."
5. **Verify:** Product is added to cart automatically
6. **Verify:** Scanner modal closes
7. **Verify:** Product appears in POS cart

**Validation:**
- Status transitions are: Membuka kamera → Tunjukkan barcode → Memindai... → (closes)
- No error messages appear during successful scan

---

### Scenario 3: Failed Barcode Decode (Real Attempt)
**Expected Behavior:** After actual decode processing attempt, error appears WITH debug token.

**Steps:**
1. Open POS camera scanner
2. Try to scan an invalid/unrecognized barcode or invalid code
3. Point camera at something that triggers decode attempt but fails (malformed barcode)
4. Wait for decoder to process frames (5-10 seconds)
5. **Verify:** Error message appears: "Gagal memproses barcode. Silakan coba lagi. [XXX_XXXX]"
   - The `[XXX_XXXX]` is the debug token (e.g., `[DECO_a1b2]`)
6. **Verify:** "Coba Lagi" (Retry) button is now visible
7. Click "Coba Lagi" to retry the scan

**Validation:**
- Debug token is present and unique per failure
- Token appears only AFTER actual decode processing, not on camera open
- Console shows structured diagnostics (see below)

---

### Scenario 4: Console Diagnostics
**Expected Behavior:** Browser console contains structured failure diagnostics.

**Steps:**
1. Open browser console (F12 → Console)
2. Trigger a camera-related failure (e.g., deny camera permission or scan invalid code)
3. **Verify:** Console shows log entries like:
   ```
   [PosCameraScanner] Diagnostic failure: {
     token: "CAMA_a1b2",
     stage: "CAMERA_PERMISSION",
     errorName: "NotAllowedError",
     errorMessage: "Permission denied",
     timestamp: "2026-03-29T..."
   }
   ```

**Stages Observed:**
- `CAMERA_PERMISSION`: Camera permission denied
- `CAMERA_UNAVAILABLE`: No camera available on device
- `CAMERA_BUSY`: Camera in use by another app
- `DECODER_INIT`: ZXing initialization failure
- `DECODER_INVALID_API`: ZXing API incompatibility
- `DECODE_PROCESSING`: Actual decode processing failure

---

### Scenario 5: ZXing Load Verification
**Expected Behavior:** ZXing library loads from deterministic version (0.20.0).

**Steps:**
1. Open browser DevTools (F12)
2. Go to Network tab
3. Open POS sell interface
4. Look for script request with "@zxing/library"
5. **Verify:** URL shows `@zxing/library@0.20.0/umd/index.min.js`
6. **Verify:** Status is 200 (successfully loaded)
7. **Verify:** NOT `@zxing/library@latest`

---

### Scenario 6: Resolver Parity (Camera vs Enter vs Helper)
**Expected Behavior:** Camera, Enter key, and helper button all resolve codes the same way.

**Steps:**
1. Prepare a test product barcode (e.g., "TEST-PROD-001")
2. Test via keyboard Enter:
   - Type code in search field
   - Press Enter
   - Product is resolved and added
3. Test via helper button:
   - Type same code in search field
   - Click "Pindai" (helper button)
   - Product is resolved and added
4. Test via camera:
   - Click camera button
   - Scan same barcode
   - Product is resolved and added

**Validation:**
- All three triggers resolve the same product
- Cart behavior is identical (product added with same quantity/serial handling)
- No differences in error handling between triggers

---

### Scenario 7: State Transitions (Deterministic Flow)
**Expected Behavior:** Status text follows a deterministic progression.

**Steps:**
1. Open camera scanner and observe status progression

**Expected Order:**
- `Membuka kamera...` (opening camera)
- `Tunjukkan barcode ke kamera` (ready, waiting for barcode)
- `Memindai...` (decode processing in progress)
- `Produk ditambahkan ke keranjang.` (success) or `Gagal memproses barcode...` (error)

**Validation:**
- Status never shows "Gagal memproses barcode" until after active decode processing
- Status transitions are smooth and consistent
- No jumping between states or unexpected messages

---

### Scenario 8: Network & Permission Edge Cases
**Expected Behavior:** Graceful handling of unavailable resources.

**Steps to test camera permission denial:**
1. Revoke camera permission from browser settings for this site
2. Click camera button
3. **Verify:** Shows "Akses kamera ditolak. Periksa pengaturan privasi Anda."
4. **Verify:** Retry button appears
5. **Verify:** No "Gagal memproses barcode" error (permission error shows instead)

**Steps to test no camera available:**
1. On a device without camera (or disconnect USB camera)
2. Click camera button
3. **Verify:** Shows "Kamera tidak tersedia atau sedang digunakan."
4. **Verify:** Retry button appears

**Steps to test camera in use:**
1. Open another app that uses camera
2. Click POS camera button
3. **Verify:** Shows appropriate message about camera being busy
4. **Verify:** Close other app, retry works

---

## Validation Checklist

- [ ] Camera opens without immediate error messages
- [ ] No "Gagal memproses barcode" appears during idle camera state
- [ ] Successful barcode scans add products to cart
- [ ] Failed decode shows error with debug token: "Gagal memproses barcode. Silakan coba lagi. [XXX_XXXX]"
- [ ] Debug token is unique and changes each time
- [ ] Browser console shows structured diagnostics with stage codes
- [ ] ZXing loads from version 0.20.0 (pinned, not @latest)
- [ ] Camera, Enter key, and helper button resolve codes identically
- [ ] Status text transitions are deterministic and smooth
- [ ] Camera permission errors show appropriate message (not decode error)
- [ ] Camera unavailable errors handled gracefully
- [ ] Retry button works for error recovery
- [ ] All tests pass: `php artisan test Modules/Pos/Tests/Feature/ --filter "Scan"`

## Troubleshooting

### Issue: Error appears immediately when camera opens
- Check browser console for ZXing load errors
- Verify ZXing URL loads successfully in Network tab
- Ensure ZXing is loading from `0.20.0` version

### Issue: Debug token not showing in error message
- Check that error message contains both text and `[XXX_XXXX]` token
- Verify browser console shows `generateDebugToken()` call
- Check that `hasAttemptedDecode` is properly set

### Issue: Camera scanner not responding
- Clear browser cache and reload
- Check browser permissions for camera access
- Verify camera is not in use by another app
- Try a different barcode or QR code

### Issue: Product not added to cart after scan
- Verify barcode is under 255 characters
- Check that `/pos/sell/search/resolve` endpoint is accessible
- Ensure resolver function (`window.executeScanResolve`) is exposed
- Check browser console for JavaScript errors

## Performance Notes

- ZXing initialization: ~500ms
- First frame processing: ~1-2 seconds
- Typical scan-to-resolve time: 2-5 seconds
- Mobile devices may take slightly longer

## Regression Testing

Run comprehensive test suite:
```bash
php artisan test Modules/Pos/Tests/Feature/POSSellShellScanUiTest.php
php artisan test Modules/Pos/Tests/Feature/POSScanResolveEndpointTest.php
php artisan test Modules/Pos/Tests/Feature/POSProductSearchScanTest.php
```

All 26 scan-related tests should pass.
