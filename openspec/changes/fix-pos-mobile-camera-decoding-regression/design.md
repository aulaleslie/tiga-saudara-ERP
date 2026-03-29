## Context

The POS sell page already has the correct high-level scanner contract: camera-decoded values are mirrored into the existing scan input and submitted through `window.executeScanResolve()`, while the current continuous-session flow keeps the modal open until the cashier exits. The regression appears inside the decode pipeline that was changed during the continuous-session refactor in `public/js/pos-camera-scanner.js`: the scanner now requests a more aggressive camera profile, enables `ASSUME_GS1`, and uses `decodeFromVideoElementContinuously(...)`, while earlier revisions used the simpler decode path that worked on phones for QR and retail barcodes.

This follow-up change should be surgical. The modal/session UI, shared resolver contract, and duplicate-suppression semantics are still correct product decisions. The unstable part is the frame-to-decode pipeline and the lack of usable runtime diagnostics on mobile when remote console access is unavailable.

## Goals / Non-Goals

**Goals:**
- Restore working mobile decode behavior for supported formats, especially QR, EAN-13, and CODE-128, on the POS camera scanner.
- Preserve the current continuous scanner session UX, including explicit cashier-controlled exit, duplicate suppression, cooldown, and single in-flight resolver submission.
- Remove `ASSUME_GS1` and return requested capture constraints to `1280x720`.
- Add a small optional in-modal debug panel that exposes live scanner diagnostics on mobile without requiring devtools.
- Keep the implementation narrow enough that it can be verified against the current continuous-session branch without rewriting the scanner module.

**Non-Goals:**
- Replacing ZXing with a different scanner library.
- Reworking the sell-page resolver contract, POS cart behavior, or backend scan resolution.
- Adding a full camera picker flow, torch controls, or new cashier interaction patterns unrelated to the regression.
- Making the debug panel visible by default for all users.

## Decisions

1. Preserve the current session state machine and only roll back the decode engine.
Rationale: the regression is in decoding behavior, not in the newer modal lifecycle. The current session model already provides the desired cashier UX, so the change should swap out the unstable decode path while keeping state transitions, cleanup, and resolver integration intact.
Alternative considered: revert the entire scanner module to the pre-refactor implementation. Rejected because that would also throw away the continuous-session behavior that the branch intentionally introduced.

2. Move away from `decodeFromVideoElementContinuously(...)` and back to the older working decode style, with explicit re-arming controlled by the session state machine.
Rationale: repo history shows earlier scanner revisions using the older decode path while real mobile decoding still worked. Reintroducing that decode style is the smallest credible rollback and lets the existing cooldown and duplicate-protection logic decide when the next decode attempt should start.
Alternative considered: keep the current continuous callback API and tune only hints and timing. Rejected because the regression report explicitly points to the refactor, and the safer recovery path is to restore the previously working decode mechanism first.

3. Remove `ASSUME_GS1` and restore ideal video constraints to `1280x720`.
Rationale: both changes align the runtime with the older working scanner profile and reduce the chance that the camera feed or decoded payloads are being skewed by configuration rather than by image quality.
Alternative considered: leave the new hints and higher resolution in place while only changing the API method. Rejected because the request specifically calls out both settings as part of the regression surface and they are easy to revert safely.

4. Add a lightweight debug state store that feeds an unobtrusive modal panel when enabled by a local constant or query parameter.
Rationale: mobile debugging is the main operational gap. The panel should surface scanner state, stream attachment, video dimensions, track label, last decode metadata, miss counts, last non-fatal error, last fatal token/stage, and resolver in-flight state so regressions can be diagnosed on-device.
Alternative considered: rely on console logging alone. Rejected because remote devtools access is unreliable on the affected devices.

5. Treat duplicate suppression, cooldown, and in-flight locking as invariant behavior around the new decode loop.
Rationale: the decode engine may change, but the virtual-scanner semantics must not. Accepted results still need to funnel through the same single-submit gate and re-arm only after the current outcome completes.
Alternative considered: simplify by removing cooldown or same-code suppression while rolling back decoding. Rejected because it would fix one regression by reintroducing duplicate-add risk.

## Risks / Trade-offs

- [Older decode path still needs explicit re-arm in a continuous session] → Restart decode attempts only from the existing session gates so one-shot decoding and persistent sessions remain compatible.
- [Debug panel leaks noisy internals into production UI] → Hide it behind an explicit flag and keep the layout compact within the modal.
- [Mobile failures remain device-specific even after rollback] → Surface live video dimensions, stream state, track label, and last error details in the debug panel to tighten follow-up diagnosis.
- [Changing decode behavior could disturb current duplicate protection] → Keep duplicate suppression and resolver locks outside the decode API swap, then verify them in targeted tests and manual checks.

## Migration Plan

- Update `public/js/pos-camera-scanner.js` to use the restored decode path, remove `ASSUME_GS1`, request `1280x720`, and expose a debug-state model that can render into the scanner modal.
- Update `Modules/Pos/Resources/views/sell.blade.php` to include the optional mobile debug helper container and unobtrusive scanner-modal styling.
- Update scanner-related test coverage to assert the debug helper markup and any view-visible regressions introduced by the modal changes; add focused JS coverage if the repo already has a harness for scanner logic.
- Deploy as a frontend-only change with no data migration.
- Roll back by removing the debug helper and restoring the current decode pipeline if the rollback path proves incompatible, though the expected default is that the older decode approach is the safer runtime.

## Open Questions

- Should the debug flag support only a query parameter and local constant, or also a persisted `localStorage` toggle for repeated tablet validation sessions?
- If the restored decode path still shows gaps for a specific format family, should format restrictions remain configurable per build, or is the current allowlist fixed enough for this iteration?
