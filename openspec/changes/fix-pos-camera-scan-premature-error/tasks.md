## 1. Scanner Runtime Hardening

- [x] 1.1 Refactor `public/js/pos-camera-scanner.js` state handling to separate camera-open/decoder-init failures from decode-processing failures.
- [x] 1.2 Add explicit first-attempt gating so decode-failure cashier guidance is only emitted after real decode processing starts.
- [x] 1.3 Remove or rewire incorrect decode API usage to the correct continuous decode contract for the selected ZXing browser API.

## 2. ZXing Integration and Resolver Parity

- [x] 2.1 Replace unstable `@latest` browser load with a deterministic browser-compatible ZXing source/version in `Modules/Pos/Resources/views/sell.blade.php`.
- [x] 2.2 Add runtime guard/fallback handling when ZXing is unavailable or initialization fails, without showing premature decode-failure cashier message.
- [x] 2.3 Expose or inject shared scan resolver callable so camera path uses the same resolver contract as Enter/helper triggers.

## 3. Debug Diagnostics and User Messaging

- [x] 3.1 Preserve existing cashier-facing decode error sentence and append a short debug token for support correlation.
- [x] 3.2 Add structured console diagnostics for scanner failures (stage, code, and underlying error metadata).
- [x] 3.3 Ensure status text transitions remain deterministic across opening, waiting, decoding, error, and retry flows.

## 4. Validation and Regression Coverage

- [x] 4.1 Update/add tests to cover camera-open idle behavior (no premature decode failure shown).
- [x] 4.2 Update/add tests to verify camera-triggered resolver parity with Enter/helper trigger outcomes.
- [ ] 4.3 Manually validate laptop/mobile flows for idle camera open, successful decode, and failed decode with visible debug token.
