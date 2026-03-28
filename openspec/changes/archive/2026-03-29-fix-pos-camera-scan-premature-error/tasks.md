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
- [x] 4.3 Manually validate laptop/mobile flows for idle camera open, successful decode, and failed decode with visible debug token. **Validation checklist:** Open scanner without barcode (should show 'Tunjukkan barcode ke kamera', not error); present valid barcode (should resolve or show 'Barcode tidak dikenali'); present invalid/partial barcode (should remain in 'Memindai...' state, not show fatal error with debug token).

## 5. Mobile Validation Follow-up (Samsung A55 Findings)

- [x] 5.1 Align scanner decode callsite with `@zxing/library@0.20.0` method signatures (use callback-capable continuous API for frame loop handling).
- [x] 5.2 Add deterministic handling for decode Promise rejection when stream stops/closes to avoid uncaught `Video stream has ended before any code could be detected.` runtime noise.
- [x] 5.3 Re-run manual validation on Samsung Galaxy A55 and confirm scanner no longer remains stuck at `Memindai...` during valid barcode/QR presentation. **Validation:** Open scanner on Samsung Galaxy A55; present valid barcode/QR (callback should execute and decode); monitor console for "Video stream has ended" messages (should not appear as uncaught exceptions).

## 6. ZXing Frame-Loop Error Classification and Hint Integrity

- [x] 6.1 Reclassify continuous-callback `NotFoundException` (`No MultiFormat Readers were able to detect the code.`) as recoverable frame miss and keep scanner in active waiting/decoding state.
- [x] 6.2 Ensure checksum/format recoverable decode errors do not trigger cashier-facing fatal decode guidance during ongoing frame loop.
- [x] 6.3 Reserve `DECODE_PROCESSING` diagnostics + user-visible debug token for unexpected fatal decode/runtime exceptions only.
- [x] 6.4 Replace string-based `POSSIBLE_FORMATS` hint payload with explicit `ZXing.BarcodeFormat` enum mapping.
- [x] 6.5 Remove duplicate video playback ownership so scanner lifecycle does not invoke redundant play paths.
- [x] 6.6 Manually validate Samsung Galaxy A55 with a real production barcode and confirm `Decoded:`/resolver handoff occurs at least once without premature fatal decode message. **Validation:** Open scanner on Samsung Galaxy A55; present production barcode; verify console shows "Decoded:" log; verify resolver handoff (cart update or 'not found' message); verify no debug token appears unless actual fatal error.
