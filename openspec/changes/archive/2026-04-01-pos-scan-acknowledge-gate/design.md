## Context

The POS camera scanner (`public/js/pos-camera-scanner.js`) implements a continuous-scan barcode reader inside a Bootstrap modal. It uses a state machine (`IDLE → OPENING → STARTING_CAMERA → WAITING_FOR_VIDEO → APPLYING_CONSTRAINTS → READY → SUBMITTING → COOLDOWN → READY`) with a unified `DecoderAdapter` supporting native `BarcodeDetector` and `html5-qrcode` fallback.

After decoding, `handleDecodedValue()` calls `window.executeScanResolve(value)` (defined in `sell.blade.php`), which resolves the barcode against the backend and adds products/serials to the cart. The `.finally()` handler calls `scheduleRearm()` → 450ms cooldown → `armScanner()` → READY, allowing the next scan immediately with no user gate.

The status panel (`#pos-camera-scanner-session-status`) already renders scan outcome messages (accepted/not found/error) with tone-based styling. The modal lifecycle has a race condition: `openScanner()` starts the camera pipeline before the Bootstrap modal's show animation completes, and `stopSession()` does not await the html5-qrcode async `stop()` promise.

## Goals / Non-Goals

**Goals:**
- Introduce a PAUSED state that blocks scanner re-arming until the cashier acknowledges the result
- Show product/serial name in the status panel so the cashier can verify what was scanned
- Fix the camera reopen bug by gating camera startup on `shown.bs.modal` and properly awaiting async cleanup

**Non-Goals:**
- Changing manual barcode input behavior (Enter key / helper button) — those keep current instant add
- Adding sound or haptic feedback
- Changing the scan resolve backend or API response shape
- Modifying the duplicate suppression window or cooldown timing

## Decisions

### Decision 1: Reuse the existing status panel (Option C) instead of a separate modal or overlay

**Rationale**: The status panel already renders scan outcome messages with tone-based chips. Adding a dismiss button to it is the minimal change that achieves the acknowledgment gate. A separate modal would stack on top of the camera modal (Bootstrap modal stacking is fragile). An overlay would require new DOM and positioning logic.

**Alternative considered**: (A) Overlay inside the modal body — more visually prominent but adds DOM complexity and z-index management with the video element.

### Decision 2: New `PAUSED` state in the state machine

**Rationale**: Reusing `COOLDOWN` with a flag would be ambiguous. A distinct `PAUSED` state makes the machine self-documenting: `SUBMITTING → PAUSED → (user dismiss) → COOLDOWN → READY`. The dismiss button click transitions out of PAUSED by calling `scheduleRearm()`.

### Decision 3: Gate camera start on `shown.bs.modal`

**Rationale**: The video element has zero dimensions during the Bootstrap modal show animation. Some browsers refuse to play video into hidden elements, causing the `waitForVideoReadiness` timeout. Using `$(modalElement).one('shown.bs.modal', ...)` ensures the modal is fully visible before `getUserMedia` + video attachment.

### Decision 4: Await html5-qrcode `stop()` promise in `DecoderAdapter.stop()`

**Rationale**: `Html5Qrcode.stop()` returns a Promise. The current fire-and-forget `try/catch` allows the DOM container to be cleared while the library is still shutting down its internal camera stream. Making `DecoderAdapter.stop()` return a Promise and awaiting it in `stopSession()` prevents resource contention on reopen.

### Decision 5: Enrich messages in `executeScanResolve`, not in the camera scanner

**Rationale**: `executeScanResolve` has access to the full response data (`response.product.product_name`, `response.serial.serial_number`). The camera scanner only sees the return object. Enriching the message at the source keeps the camera scanner decoupled from response shape details. The camera scanner simply displays `result.message`.

## Risks / Trade-offs

- **Slower scanning throughput** → Intentional; the user explicitly requested a stop between scans for verification. Experienced cashiers may find it slower, but accuracy is the priority.
- **`shown.bs.modal` adds ~300ms latency on first open** → Acceptable; the camera boot delay (`CAMERA_BOOT_DELAY_MS = 240ms`) already exists. The modal animation (~150-300ms) replaces part of this delay rather than adding to it.
- **Double `stopSession` call on close** → `closeScanner()` calls `stopSession()`, then `hidden.bs.modal` calls it again. Both calls are idempotent (check `sessionActive` / `mediaStream` guards), so no functional issue. Not worth adding a flag to prevent the second call.
