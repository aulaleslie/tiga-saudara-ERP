## 1. Mobile Camera Telemetry Recovery

- [x] 1.1 Extend `public/js/pos-camera-scanner.js` debug state so it records not only track label, but also relevant `getCapabilities()` and `getSettings()` summaries for the active video track.
- [x] 1.2 Record requested post-start constraints and whether each `applyConstraints(...)` attempt succeeded, failed, or was unsupported, so mobile focus issues are diagnosable without devtools.
- [x] 1.3 Update the optional in-modal debug surface in `Modules/Pos/Resources/views/sell.blade.php` so the new camera diagnostics remain readable on mobile devices.

## 2. ZXing-First Camera Pipeline Recovery

- [x] 2.1 Keep ZXing as the decode engine, but separate camera acquisition from decode readiness so decoding starts only after the stream is attached, dimensions are known, playback is active, and post-start constraint attempts have completed.
- [x] 2.2 Rework post-start camera handling in `public/js/pos-camera-scanner.js` to inspect supported capabilities and apply only meaningful advanced constraints such as focus-related settings and optional zoom when available.
- [x] 2.3 Preserve the continuous-session UX, duplicate suppression, cooldown, and single in-flight resolver submission while restoring the previously working decode path if that remains the safest ZXing integration on mobile.
- [x] 2.4 Revisit format restrictions and decode timing only after the camera stream is verifiably scan-grade, so decoder tuning is not used to mask a blurry preview problem.

## 3. Regression Verification

- [x] 3.1 Update or add scanner-facing tests for the expanded debug diagnostics and camera/decode lifecycle expectations where the current test tooling allows.
- [x] 3.2 Add focused verification for the recovered ZXing path, including post-start constraint bookkeeping, duplicate suppression invariants, and decode re-arm behavior.
- [ ] 3.3 Re-run manual validation on Samsung Galaxy A55 with the in-modal debug helper enabled, confirming whether preview sharpness, QR decode, and EAN-13 decode return after the camera-pipeline recovery.
- [ ] 3.4 Compare the new manual validation results against the previous regression report and capture any remaining device-specific limitations before marking the change complete.
