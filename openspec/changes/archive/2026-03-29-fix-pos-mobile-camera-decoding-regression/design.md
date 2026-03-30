## Context

The POS sell page already has the correct high-level scanner contract: camera-decoded values are mirrored into the existing scan input and submitted through `window.executeScanResolve()`, while the current continuous-session flow keeps the modal open until the cashier exits. The regression appears inside the camera-to-decode pipeline that was changed during the continuous-session refactor in `public/js/pos-camera-scanner.js`: the scanner now uses a different decode lifecycle and newer camera handling assumptions, while earlier revisions used a simpler path that worked on phones for QR and retail barcodes.

Manual exploration on Samsung Galaxy A55 adds an important correction to the original diagnosis. The in-modal debug panel can show a rear-facing track label (`camera 2, facing back`), yet the preview remains blurry and no QR or barcode resolves after extended attempts, even though a dedicated scanner app on the same phone scans successfully. That means the current failure is not explained by decoder output alone. A back-facing track does not guarantee the correct physical lens, usable close-focus behavior, or scan-grade video frames.

This follow-up change should stay surgical in product surface area, but it can no longer be only a decode-method rollback. The modal/session UI, shared resolver contract, and duplicate-suppression semantics are still correct product decisions. The unstable part is the mobile camera pipeline: stream acquisition, post-start focus behavior, decode startup timing, and the lack of usable runtime diagnostics on mobile when remote console access is unavailable.

## Goals / Non-Goals

**Goals:**
- Restore working mobile decode behavior for supported formats, especially QR, EAN-13, and CODE-128, on the POS camera scanner.
- Preserve the current continuous scanner session UX, including explicit cashier-controlled exit, duplicate suppression, cooldown, and single in-flight resolver submission.
- Keep ZXing as the decode engine while recovering the surrounding camera pipeline on mobile devices.
- Apply and verify post-start camera constraints such as focus-related settings instead of assuming the initial request is enough.
- Add a small optional in-modal debug panel that exposes live scanner diagnostics on mobile without requiring devtools, including actual capabilities/settings and constraint-application outcomes.
- Keep the implementation narrow enough that it can be verified against the current continuous-session branch without rewriting the scanner module.

**Non-Goals:**
- Replacing ZXing with a different scanner library.
- Reworking the sell-page resolver contract, POS cart behavior, or backend scan resolution.
- Adding a full camera picker flow, torch controls, or new cashier interaction patterns unrelated to the regression.
- Making the debug panel visible by default for all users.

## Decisions

1. Preserve the current session state machine and recover the camera pipeline around ZXing rather than replacing the scanner architecture.
Rationale: the regression is in mobile capture/decode behavior, not in the newer modal lifecycle. The current session model already provides the desired cashier UX, so the change should keep state transitions, cleanup, and resolver integration while fixing the stream acquisition and decode startup path.
Alternative considered: revert the entire scanner module to the pre-refactor implementation. Rejected because that would also throw away the continuous-session behavior that the branch intentionally introduced.

2. Separate camera acquisition from decode readiness.
Rationale: the current exploration shows that a stream can be attached and back-facing while still being optically unusable for scanning. Decode should begin only after the stream is attached, playback is running, dimensions are known, and any post-start constraints have either succeeded or failed explicitly.
Alternative considered: keep starting decode immediately on `loadedmetadata`. Rejected because that conflates "stream exists" with "stream is scan-ready" and makes mobile regressions harder to reason about.

3. Inspect actual track capabilities and settings, then apply advanced constraints after stream start.
Rationale: initial `getUserMedia(...)` requests often do not tell us what the browser actually gave us. On Galaxy A55, `Track: camera 2, facing back` proved only that a rear track was selected, not that focus behavior was suitable. The scanner should read `getCapabilities()` and `getSettings()`, apply supported post-start constraints such as `focusMode`, `zoom`, and related settings, and expose the exact outcome in diagnostics.
Alternative considered: rely only on initial `getUserMedia(...)` constraints. Rejected because the regression evidence shows that "back-facing stream acquired" is insufficient and the current implementation swallows useful focus-constraint outcomes.

4. Keep the decode engine on ZXing, but reduce mobile ambiguity with narrower, verified decoder setup.
Rationale: ZXing is not yet disproven. The stronger evidence points to camera quality before decode. The scanner should therefore keep ZXing while ensuring format restrictions, retry behavior, and decode startup happen against a known stream profile rather than against a blurry, unverified camera feed.
Alternative considered: replace ZXing with another scanner library immediately. Rejected because the current evidence is stronger against camera handling and observability than against the decoder itself.

5. Add a lightweight debug state store that feeds an unobtrusive modal panel when enabled by a local constant or query parameter.
Rationale: mobile debugging is the main operational gap. The panel should surface scanner state, stream attachment, video dimensions, track label, track capabilities/settings summary, requested and applied post-start constraints, last decode metadata, miss counts, last non-fatal error, last fatal token/stage, and resolver in-flight state so regressions can be diagnosed on-device.
Alternative considered: rely on console logging alone. Rejected because remote devtools access is unreliable on the affected devices.

6. Treat duplicate suppression, cooldown, and in-flight locking as invariant behavior around the recovered camera/decode loop.
Rationale: the decode engine may change, but the virtual-scanner semantics must not. Accepted results still need to funnel through the same single-submit gate and re-arm only after the current outcome completes.
Alternative considered: simplify by removing cooldown or same-code suppression while rolling back decoding. Rejected because it would fix one regression by reintroducing duplicate-add risk.

## Risks / Trade-offs

- [Post-start constraint handling varies by browser/device] -> Read and expose actual capabilities/settings, treat unsupported constraints as diagnosable outcomes rather than silent no-ops.
- [Older decode path still needs explicit re-arm in a continuous session] -> Restart decode attempts only from the existing session gates so one-shot decoding and persistent sessions remain compatible.
- [Debug panel leaks noisy internals into production UI] → Hide it behind an explicit flag and keep the layout compact within the modal.
- [Mobile failures remain device-specific even after rollback] -> Surface live video dimensions, stream state, track label, track settings/capabilities, and last error details in the debug panel to tighten follow-up diagnosis.
- [Changing decode behavior could disturb current duplicate protection] → Keep duplicate suppression and resolver locks outside the decode API swap, then verify them in targeted tests and manual checks.

## Migration Plan

- Update `public/js/pos-camera-scanner.js` so camera acquisition and decode startup are separate phases, with track capability/settings inspection before active decode.
- Update `public/js/pos-camera-scanner.js` to keep ZXing, but apply supported advanced constraints after stream start and expose whether they actually took effect.
- Update `public/js/pos-camera-scanner.js` to use the restored decode path if needed, keep supported format restrictions explicit, and start decode only after the stream is in a known state.
- Update `Modules/Pos/Resources/views/sell.blade.php` to include the optional mobile debug helper container and unobtrusive scanner-modal styling.
- Update scanner-related test coverage to assert the debug helper markup and any view-visible regressions introduced by the modal changes; add focused JS coverage if the repo already has a harness for camera diagnostics and decode lifecycle logic.
- Deploy as a frontend-only change with no data migration.
- Roll back by removing the added diagnostics and restoring the current camera/decode pipeline if the recovery path proves incompatible, though the expected default is that explicit post-start camera handling is the safer runtime.

## Open Questions

- Should the debug flag support only a query parameter and local constant, or also a persisted `localStorage` toggle for repeated tablet validation sessions?
- If the recovered ZXing path still shows gaps for a specific format family, should format restrictions remain configurable per build, or is the current allowlist fixed enough for this iteration?
- Should the scanner attempt a modest post-start zoom on devices that expose zoom capability, or should the first recovery step stay limited to focus telemetry and decode timing?
