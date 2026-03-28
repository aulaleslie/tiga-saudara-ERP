## Context

The POS camera scanner was introduced to decode one barcode/QR value and route it through existing resolver behavior used by Enter/helper scan flows. In current runtime behavior, users can receive `Gagal memproses barcode. Silahkan coba lagi.` immediately after camera open, before any barcode is shown, which violates expected cashier flow and makes troubleshooting difficult.

Observed constraints in the current implementation:
- Scanner startup and decoder initialization are coupled to one generic decode error path.
- ZXing is loaded from an unstable browser entrypoint (`@latest`) and decode API usage does not match the continuous callback contract.
- Camera flow expects a globally available shared resolver function, but current script scope can break that parity contract.
- Existing backend resolver contract (`/pos/sell/search/resolve`, max length 255) remains correct and must be preserved.

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

## Risks / Trade-offs

- [Pinned ZXing source can lag upstream fixes] -> Mitigation: pin to tested version and update intentionally via dependency maintenance.
- [More scanner states increase implementation complexity] -> Mitigation: keep a minimal explicit state map and deterministic transitions.
- [Visible debug token may confuse some users] -> Mitigation: keep token short and unobtrusive; preserve existing Bahasa guidance.
- [Global resolver exposure may widen coupling] -> Mitigation: expose a narrow, documented callable contract only.

## Migration Plan

1. Update scanner load strategy and runtime guards in POS sell view/scripts.
2. Refactor scanner lifecycle handling to separate startup errors from decode-attempt errors.
3. Add debug token generation and stage-aware logging for scanner failures.
4. Ensure camera resolver path invokes the shared resolver contract used by Enter/helper.
5. Update/add tests and perform manual validation:
   - camera open with no barcode should not show decode-failure message,
   - first decode failure should show cashier message plus debug token,
   - resolved scans still follow existing cart/serial behavior.

Rollback strategy:
- Revert the change commit to restore previous scanner behavior and script wiring; backend API remains untouched.

## Open Questions

- Should debug tokens be localized labels (Bahasa) or stable English-style codes (e.g., `SCN_INIT_ZXING`) for support consistency?
- Should scanner diagnostics later be optionally reported to backend logs, or remain browser-console only for now?
