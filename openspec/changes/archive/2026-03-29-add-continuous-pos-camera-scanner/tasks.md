## 1. Scanner Session UX

- [x] 1.1 Update the POS camera scanner modal in `Modules/Pos/Resources/views/sell.blade.php` to behave as a persistent scanner surface with explicit close control, in-session status area, and a mobile-first scan guide overlay.
- [x] 1.2 Keep the current scan action rail and shared scan input workflow intact so hardware scanner, Enter, helper action, and camera scan still converge on the same sell-page interaction model.
- [x] 1.3 Add scanner-session feedback states for ready, accepted scan, warning, and retryable error outcomes without requiring the modal to close between scans.

## 2. Continuous Camera Session Logic

- [x] 2.1 Refactor `public/js/pos-camera-scanner.js` from first-decode-close behavior into a continuous session state machine that re-arms after each accepted outcome until the cashier explicitly exits.
- [x] 2.2 Preserve camera-decoded parity by continuing to mirror accepted values into the existing scan input and submit them through `window.executeScanResolve()`.
- [x] 2.3 Add duplicate-protection rules for continuous decoding, including one in-flight submission lock, same-code suppression window, and short re-arm cooldown before the next accepted scan.
- [x] 2.4 Preserve deterministic cleanup for explicit close, modal hide, retry, and page unload events while keeping rear-camera preference with available-camera fallback.

## 3. Verification

- [x] 3.1 Add or update POS feature/UI coverage for the modified `pos-scan-input-actions` capability, including continuous-session expectations and explicit close semantics.
- [x] 3.2 Verify camera scan parity with existing resolver outcomes for product match, serial match, not-found, and resolver-error cases while the scanner session remains open.
- [x] 3.3 Verify duplicate suppression prevents repeated adds when the same barcode remains in frame, while distinct next items can still be scanned sequentially without reopening the scanner.
- [x] 3.4 Manually validate tablet-oriented scanner ergonomics, including scan guide visibility, status readability, repeated multi-item scan flow, and cleanup after session exit.
