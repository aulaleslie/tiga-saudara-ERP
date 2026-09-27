## Context

The POS camera scanner was introduced to decode one barcode/QR value and route it through existing resolver behavior used by Enter/helper scan flows. In current runtime behavior, users can receive `Gagal memproses barcode. Silahkan coba lagi.` immediately after camera open, before any barcode is shown, which violates expected cashier flow and makes troubleshooting difficult.

Observed constraints in the current implementation:
- Scanner startup and decoder initialization are coupled to one generic decode error path.
- ZXing is loaded from an unstable browser entrypoint (`@latest`) and decode API usage does not match the continuous callback contract.
- Camera flow expects a globally available shared resolver function, but current script scope can break that parity contract.
- Existing backend resolver contract (`/pos/sell/search/resolve`, max length 255) remains correct and must be preserved.

Field validation findings (2026-03-29, Samsung Galaxy A55):
- Scanner opens and status enters `Memindai...`, but decode callback path never executes.
- Console shows repeated `Trying to play video that is already playing.` followed by `Video stream has ended before any code could be detected.` when scanner closes.
- No `Decoded:` or resolver-handoff logs are emitted during active camera session.

Debug-log findings from current implementation (2026-03-29):
- Runtime emits repeated `DECODE_PROCESSING` diagnostic failures every ~29-58ms with `errorName: 'N'` and message `No MultiFormat Readers were able to detect the code.`.
- This pattern matches continuous frame-loop "not found yet" behavior, not a fatal decode crash, and is currently being classified as cashier-facing decode failure.
- Current format hint wiring uses string literals instead of ZXing `BarcodeFormat` enum values, which weakens deterministic reader selection and can degrade mobile detection behavior.

Root cause identified from runtime + library contract inspection:
- Current scanner code calls `decodeFromVideoElement(videoElement, callback)` as if it were continuous callback API.
- In `@zxing/library@0.20.0`, `decodeFromVideoElement` is one-shot Promise API; callback-based continuous mode is `decodeFromVideoElementContinuously`.
- The callback argument is ignored in the current callsite, so decode result/error handling in scanner callback never runs and UI can remain stuck in decoding state.

Stakeholders:
- Cashier users need deterministic camera-open behavior without false failures.
- Support/developers need actionable debug data when decode/runtime failures occur.

## Goals / Non-Goals

**Goals:**
- Prevent decode-failure UI from appearing during camera-open idle state.
- Show decode failure only after a real scan processing attempt fails.
- Add stable debug diagnostics for scanner/runtime failures while preserving cashier-facing guidance.
- Use browser-compatible ZXing loading and API wiring that avoids immediate runtime exceptions.
- Preserve resolver parity with Enter/helper triggers and keep backend contract unchanged.

**Non-Goals:**
- Redesign resolver endpoint behavior or payload validation rules.
- Add multi-item continuous scanning workflow.
- Introduce server-side telemetry persistence in this change.

## Decisions

### Decision 1: Split scanner failures by lifecycle stage
Introduce explicit failure stages (camera open, decoder init, frame decode, resolver handoff) and map each stage to distinct handling.

Rationale:
- Premature error is caused by treating startup/runtime exceptions as if a decode attempt already happened.
- Stage-aware handling allows user-safe idle behavior and better diagnostics.

Alternatives considered:
- Keep a single generic error state: rejected because it cannot prevent false decode-failure signaling.

### Decision 2: Gate cashier decode-failure message behind first real scan attempt
`DECODE_ERROR` cashier text will only render after scanner enters active frame-processing state and a decode-processing attempt fails.

Rationale:
- Matches expected operational semantics: no decode failure before scan attempt.
- Avoids false-negative UX on camera startup or library-load issues.

Alternatives considered:
- Suppress all errors until successful decode: rejected because it hides legitimate operational failures.

### Decision 3: Standardize ZXing browser integration
Use a deterministic browser-compatible ZXing source/version and align implementation with the library’s continuous decode API contract.

Rationale:
- Prevents runtime mismatch from `@latest` entrypoint drift.
- Removes callback/API incompatibility that can throw before scanning.

Alternatives considered:
- Keep `@latest` CDN for convenience: rejected due to compatibility drift risk.

### Decision 4: Re-establish resolver parity contract explicitly
Expose or inject the shared resolver entrypoint used by Enter/helper so camera scan path always resolves through the same contract.

Rationale:
- Current camera module expects `window.executeScanResolve`; parity breaks if function remains scoped.
- Explicit contract prevents trigger-path drift.

Alternatives considered:
- Duplicate resolver logic in camera module: rejected due to divergence and maintenance risk.

### Decision 5: Add structured debug message and console diagnostics
Keep existing cashier-facing error sentence and append short debug context token (stage/code), while logging detailed error metadata to console.

Rationale:
- Cashier messaging stays familiar.
- Debug token accelerates troubleshooting without exposing stack traces to users.

Alternatives considered:
- Console-only diagnostics: rejected because support teams often need user-visible correlation code.

### Decision 6: Enforce ZXing method-signature correctness for continuous camera decode
The scanner runtime MUST use the continuous decode entrypoint that actually supports callback delivery for frame-by-frame decode handling, and MUST not pass callback handlers to one-shot Promise-only methods.

Rationale:
- Prevents silent callback bypass that leaves scanner status stuck in `Memindai...`.
- Ensures decoded value and failure handling paths execute deterministically on mobile browsers.

Alternatives considered:
- Keep one-shot decode path and poll externally: rejected due to higher complexity and weaker parity with existing scanner state machine.

### Decision 7: Treat ZXing `NotFoundException` as recoverable frame miss, not decode-processing failure
When running continuous decode, per-frame `NotFoundException` (including message `No MultiFormat Readers were able to detect the code.`) MUST keep scanner in active waiting/decoding state and MUST NOT trigger user-facing decode failure or debug-token escalation.

Rationale:
- In continuous scanning, most frames do not contain a readable code; this is expected control flow.
- Classifying frame misses as fatal is the direct cause of premature/flooded `DECODE_PROCESSING` diagnostics and blocked scan UX.

Alternatives considered:
- Keep current "non-FormatException means fatal" rule: rejected because ZXing emits `NotFoundException` frequently by design in frame loops.

### Decision 8: Use enum-based ZXing format hints
Format allowlists MUST be mapped to `ZXing.BarcodeFormat.*` values before setting `DecodeHintType.POSSIBLE_FORMATS`.

Rationale:
- Ensures deterministic reader construction consistent with ZXing internals.
- Reduces ambiguity from string-based format wiring and improves scan reliability on constrained mobile devices.

Alternatives considered:
- Omit hints entirely: rejected because it broadens reader attempts and can increase per-frame decode latency/noise.

### Decision 9: Eliminate duplicate video playback ownership
Scanner runtime SHOULD avoid double-driving `video.play()` when ZXing decode setup already handles video playback lifecycle.

Rationale:
- Prevents "video already playing" churn and removes lifecycle race conditions during start/stop transitions.
- Keeps stream management deterministic across open, scan, and close.

Alternatives considered:
- Keep both manual play and ZXing-managed play for redundancy: rejected due to noisy behavior and harder state reasoning.

## Risks / Trade-offs

- [Pinned ZXing source can lag upstream fixes] -> Mitigation: pin to tested version and update intentionally via dependency maintenance.
- [More scanner states increase implementation complexity] -> Mitigation: keep a minimal explicit state map and deterministic transitions.
- [Visible debug token may confuse some users] -> Mitigation: keep token short and unobtrusive; preserve existing Bahasa guidance.
- [Global resolver exposure may widen coupling] -> Mitigation: expose a narrow, documented callable contract only.
- [Suppressing frame-miss errors may reduce raw diagnostic volume] -> Mitigation: keep throttled debug-level logs for recoverable misses and preserve tokenized diagnostics for fatal failures only.

## Migration Plan

1. Update scanner load strategy and runtime guards in POS sell view/scripts.
2. Refactor scanner lifecycle handling to separate startup errors from decode-attempt errors.
3. Add debug token generation and stage-aware logging for scanner failures.
4. Ensure camera resolver path invokes the shared resolver contract used by Enter/helper.
5. Correct decode method wiring to match `@zxing/library@0.20.0` method signatures (continuous callback path vs one-shot Promise path).
6. Add explicit handling for decode Promise rejection on stream stop/close so runtime does not emit unhandled errors.
7. Reclassify ZXing continuous-callback recoverable errors (`NotFoundException`, checksum/format where applicable) so scanner remains in active waiting/decoding state without cashier-facing decode-failure guidance.
8. Replace string-based format hints with explicit `ZXing.BarcodeFormat` enum mapping before reader construction.
9. Remove duplicate video-play lifecycle ownership to avoid playback race/noise during decode startup.
10. Update/add tests and perform manual validation:
   - camera open with no barcode should not show decode-failure message,
   - camera pointing at non-code background should not escalate to fatal decode error,
   - first decode failure should show cashier message plus debug token,
   - resolved scans still follow existing cart/serial behavior,
   - Samsung Galaxy A55 flow does not remain stuck in `Memindai...` when valid code is presented.

Rollback strategy:
- Revert the change commit to restore previous scanner behavior and script wiring; backend API remains untouched.

## Open Questions

- Should debug tokens be localized labels (Bahasa) or stable English-style codes (e.g., `SCN_INIT_ZXING`) for support consistency?
- Should scanner diagnostics later be optionally reported to backend logs, or remain browser-console only for now?
- Should scanner runtime stay on `@zxing/library` (deprecated browser reader path) or migrate to `@zxing/browser` to reduce API-contract drift risk?
