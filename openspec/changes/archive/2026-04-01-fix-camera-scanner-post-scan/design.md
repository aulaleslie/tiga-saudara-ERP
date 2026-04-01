## Context

The POS camera scanner (`public/js/pos-camera-scanner.js`) implements a barcode scanning modal with two decoder backends:

- **Native**: Uses `BarcodeDetector` API with a `requestAnimationFrame` loop that continuously detects barcodes from a `<video>` element fed by `getUserMedia`.
- **Fallback**: Uses the `html5-qrcode` library which manages its own camera stream and renders into a container div.

After a barcode is decoded, the flow is: `handleDecodedValue()` → `executeScanResolve()` → `.finally()` → `scheduleRearm()` (450ms cooldown) → `armScanner()` → `restartDecoding()`.

The critical problem is that `restartDecoding()` calls `decoderAdapter.stop()` which destroys both decoder instances, then calls `startDecoding()` which is native-only (checks `videoElement.srcObject` — null for fallback). This kills the fallback camera permanently. For native, it unnecessarily destroys and recreates the BarcodeDetector + rAF loop.

Additionally, `armScanner()` unconditionally sets the session message to a generic "Ready" message, wiping the scan result that was briefly displayed.

## Goals / Non-Goals

**Goals:**
- Camera decoder remains active after a successful scan for both native and fallback backends
- Scanned barcode value is displayed in the scanner dialog alongside the resolve outcome
- Scan result persists in the dialog until the next scan begins
- Maintain existing duplicate suppression (`SAME_CODE_SUPPRESSION_MS`, `submissionInFlight` guard, state check)

**Non-Goals:**
- Changing the resolver endpoint or its response contract
- Adding scan history/log to the scanner dialog
- Modifying the scanner modal layout or adding new DOM elements
- Changing the native ↔ fallback backend selection logic

## Decisions

### Decision 1: Remove `restartDecoding()` call from `armScanner()`

**Choice**: Let the decoder keep running during SUBMITTING → COOLDOWN → READY transitions. Do not stop/restart it.

**Rationale**: Both backends are already running during SUBMITTING and COOLDOWN. The `handleDecodedValue()` function already guards against processing during these states:
```
if (submissionInFlight || state === States.COOLDOWN) { return; }
```
Plus `SAME_CODE_SUPPRESSION_MS` (1800ms) prevents re-processing the same barcode. Stopping and restarting adds complexity and breaks the fallback path entirely.

**Alternative considered**: Make `restartDecoding()` backend-aware (check `decoderAdapter.getSelectedBackend()` and call `startFallbackSession()` for fallback). Rejected because it's more complex and the stop/restart is unnecessary for both backends.

### Decision 2: Include scanned code in post-resolve messages

**Choice**: Modify the `.then()` handler in `handleDecodedValue()` to embed `lastAcceptedCode` in the detail text passed to `setSessionMessage()`. Each outcome (product_exact, serial_exact, not_found, error) will include the scanned code value.

**Rationale**: The scanned code is already stored in `lastAcceptedCode` at this point. Including it in the message is a simple string concatenation. No need for new DOM elements or state.

### Decision 3: Preserve scan result message — don't overwrite with "Ready"

**Choice**: In `armScanner()`, after a scan cycle, skip the `setSessionMessage(Messages.READY)` call. Only show the "Ready" message on initial scanner arm (first time opening, or after retry).

**Implementation**: Introduce a boolean flag `lastScanDisplayed` (or similar). Set it to `true` after a scan result is displayed. In `armScanner()`, check this flag — if true, skip the "Ready" message and just transition state to READY. Reset the flag when `handleDecodedValue()` begins processing a new code (so the next scan's "Submitting" message will display normally).

**Alternative considered**: Using a timer to show the result for N seconds then switch to "Ready". Rejected because there's no benefit — the result should stay until the next scan, and adding a timer increases complexity.

## Risks / Trade-offs

- **[Decoder runs during cooldown]** → The rAF loop (native) or html5-qrcode continuous decode will keep calling `handleDecodedValue()` with the same barcode during cooldown. Mitigated by existing `SAME_CODE_SUPPRESSION_MS` (1800ms) and `state === COOLDOWN` guard. The 450ms cooldown (`REARM_COOLDOWN_MS`) is well within the suppression window.

- **[Nonce invalidation no longer needed for rearm]** → Currently `restartDecoding()` increments `decodeStartNonce` implicitly by calling `startDecoding()` which does `++decodeStartNonce`. Without the restart, the nonce stays stable — which is correct because we want the same decode callbacks to keep working. No risk.

- **[html5-qrcode continuous memory]** → The html5-qrcode instance stays alive for the entire scanner session. This is already the intended design (the modal subtitle says "Sesi tetap terbuka sampai kasir menutup scanner"). No change in memory behavior — the instance was always created for the session duration.
