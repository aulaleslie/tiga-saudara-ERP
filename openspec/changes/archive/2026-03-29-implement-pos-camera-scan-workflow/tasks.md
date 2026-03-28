## 1. Scanner Dependency and POS Camera Entry Activation

- [x] 1.1 Add `@zxing/browser` to frontend dependencies and ensure it is available in the POS build pipeline.
- [x] 1.2 Update POS scan action rail markup in `Modules/Pos/Resources/views/sell.blade.php` to replace reserved camera placeholder with enabled camera trigger control.
- [x] 1.3 Add scanner modal structure (video preview, status text, retry/close controls) in POS sell view with IDs/hooks required by script logic.

## 2. Camera Session Lifecycle and Device Selection

- [x] 2.1 Implement camera open/start logic that requests environment-facing camera as ideal and falls back to available camera when environment camera is unavailable.
- [x] 2.2 Implement deterministic scanner cleanup (stop all media tracks, release decoder listeners, reset in-flight flags) on modal close, cancel, and page unload.
- [x] 2.3 Implement single-hit lock behavior so first decoded payload is processed once and duplicate frame detections are ignored.

## 3. Decode Handling and Resolver Parity

- [x] 3.1 Configure decoder with agreed multi-format allowlist (EAN/UPC/CODE128/CODE39/ITF/CODABAR/QR/DATA_MATRIX/PDF_417/AZTEC).
- [x] 3.2 Mirror decoded raw value into existing `#pos-shell-search` input before resolver handling.
- [x] 3.3 Reuse existing `executeScanResolve` path for camera submissions to preserve resolver parity with Enter/helper behavior.
- [x] 3.4 Enforce frontend length guard for decoded values over 255 characters: keep value in input, skip resolver request, and show warning guidance.
- [x] 3.5 Enforce session-close policy: close scanner modal after first decode outcome for both success and not-found while preserving not-found value for manual review.

## 4. UX States, Regression Coverage, and Validation

- [x] 4.1 Add/adjust camera-specific status messaging for opening, ready, permission denied, camera unavailable/busy, decode-in-progress, not-found, and over-limit conditions.
- [x] 4.2 Update POS sell shell feature tests to assert active camera control presence and removal of reserved/disabled placeholder contract.
- [x] 4.3 Add or update behavior coverage for camera decode parity expectations (same resolver semantics as Enter/helper for found and not-found outcomes).
- [x] 4.4 Manually validate end-to-end flow on laptop webcam and mobile/tablet rear camera preference, including not-found manual-review path and deterministic stream shutdown.
