## 1. Decode Regression Recovery

- [x] 1.1 Compare the current `public/js/pos-camera-scanner.js` decode pipeline with the last known working mobile implementation and identify the smallest safe rollback that preserves the continuous session state machine.
- [x] 1.2 Refactor the scanner decode path away from `decodeFromVideoElementContinuously(...)` if needed, while keeping the modal/session lifecycle, shared resolver contract, duplicate suppression, cooldown, and single in-flight submission behavior intact.
- [x] 1.3 Remove `ASSUME_GS1`, restore ideal camera constraints to `1280x720`, and verify supported mobile formats still flow through the existing scan input and resolver path.

## 2. Mobile Debug Diagnostics

- [x] 2.1 Add an optional in-modal debug helper in `Modules/Pos/Resources/views/sell.blade.php` and corresponding scanner styles so diagnostics stay readable but unobtrusive on mobile.
- [x] 2.2 Extend `public/js/pos-camera-scanner.js` with a debug flag and runtime diagnostics model that exposes scanner state, stream attachment, video dimensions, track label, last decoded text and format, frame miss count, last non-fatal decode error, last fatal token/stage, and resolver in-flight state.

## 3. Verification

- [x] 3.1 Update or add POS scanner coverage for the modified modal markup and any scanner-facing requirements that can be asserted from feature tests.
- [x] 3.2 Add focused scanner logic verification for decode-path rollback behavior, duplicate suppression/re-arm invariants, and debug-state updates where the existing test tooling allows it.
- [x] 3.3 Manually validate the mobile scanner on a real phone for QR, EAN-13, and CODE-128 decoding, including repeated scans in one session and debug-panel visibility when the flag is enabled.
