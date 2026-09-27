## Context

The POS sell shell already treats scan resolution as a shared contract: hardware scanner input, keyboard Enter, helper-button submissions, and camera-decoded values all converge on `executeScanResolve()` in the sell page. The current camera implementation in `public/js/pos-camera-scanner.js` opens a modal, decodes the first accepted value, mirrors it into the existing scan input, calls the shared resolver, and then closes the modal after the first outcome. That behavior is stable for occasional camera use, but it conflicts with the intended tablet workflow where the device camera should act like a replacement for a dedicated barcode scanner and remain available across repeated scans.

The change is frontend-only but cross-cutting across sell-page markup, scanner state management, and POS UI tests. It must preserve the current hardware-scanner semantics on desktop while introducing a mobile-first continuous camera session that still feels like the same scan system rather than a separate workflow.

## Goals / Non-Goals

**Goals:**
- Keep the existing shared scan resolver as the only path that mutates POS scan outcomes.
- Convert camera scan sessions from single-shot modal behavior into a cashier-controlled continuous scanning session.
- Make the camera session behave like a virtual hardware scanner by accepting one discrete scan at a time, suppressing duplicates, and re-arming automatically for the next item.
- Improve mobile/tablet scan ergonomics with a clearer scan guide, persistent session status, and minimal controls appropriate for repeated scans.
- Preserve deterministic cleanup, permission handling, and fallback camera behavior.

**Non-Goals:**
- Changing backend scan resolve endpoints, cart domain logic, or product/serial matching rules.
- Changing desktop hardware scanner interactions or requiring desktop users to adopt the camera flow.
- Introducing a new scanner dependency or replacing ZXing as part of this change.
- Adding manual camera device pickers as the default tablet flow.

## Decisions

1. Keep `executeScanResolve()` as the authoritative submission contract for camera-decoded values.
Rationale: hardware scanner, Enter, helper action, and camera decode already share one resolver path. Preserving that contract avoids business-rule drift and ensures cart/status outcomes remain aligned.
Alternative considered: create a camera-specific resolve path with bespoke success messaging. Rejected because it would fork scan semantics and increase regression risk.

2. Replace first-decode-close behavior with a persistent session state machine that re-arms after resolver completion.
Rationale: a tablet camera replacing a scanner device must stay available across repeated scans until the cashier explicitly exits. Re-arming after each accepted outcome preserves cashier rhythm while keeping the camera flow appliance-like.
Alternative considered: keep one-shot sessions and optimize reopen speed. Rejected because repeated modal churn still feels unlike a scanner device and adds friction per item.

3. Add acceptance gating with one in-flight submission lock, short global cooldown, and per-code dedupe window.
Rationale: continuous decode streams see the same barcode across many frames. To emulate scanner-device semantics, the camera session must convert repeated visual detections into one discrete accepted scan event at a time.
Alternative considered: close after each scan to avoid duplicates. Rejected because it solves duplicates by sacrificing the desired continuous session behavior.

4. Keep the scanner UI modal-based but treat it as a persistent scanner surface during the session.
Rationale: the current sell shell already has modal infrastructure and scanner hooks. A modal can still serve the tablet workflow if it becomes a large, low-friction, continuously armed scanner surface with explicit close control, scan guide overlay, and session feedback.
Alternative considered: build a dedicated full-page scanner route. Rejected because it would be a larger navigation and layout change than necessary for this iteration.

5. Use a mobile-first horizontal scan lane overlay rather than a purely decorative square frame.
Rationale: the expected primary use is product barcode scanning on tablets, where 1D retail barcodes are common. A horizontal lane communicates aiming better for that workload, even if QR support remains available.
Alternative considered: keep raw video with status text only. Rejected because users benefit from explicit aiming guidance, and the lack of a guide makes the camera feel less polished and less appliance-like.

6. Preserve rear-camera preference and available-camera fallback, but avoid introducing camera selection UI unless auto-selection proves insufficient.
Rationale: the target tablet flow values one-tap launch over camera management. Existing `facingMode: environment` preference is directionally correct; this change should improve session behavior first and keep desktop hardware scanners as the normal desktop path.
Alternative considered: always expose a camera picker. Rejected because it adds decision overhead to the common mobile flow.

## Risks / Trade-offs

- [Duplicate adds from repeated frame detections] → Enforce lock/cooldown/dedupe rules before invoking the shared resolver.
- [Continuous session drifts from hardware-scanner semantics] → Keep a single shared resolver path and define the camera as a virtual scan emitter, not a separate business flow.
- [Long-running modal feels heavy on small tablets] → Keep controls minimal, prioritize a clear scan lane, and keep scanner feedback compact.
- [User uncertainty after not-found or error outcomes] → Keep the session open and show explicit in-session status so the cashier can immediately retry or re-aim without reopening the scanner.
- [Browser/device variance in camera behavior] → Preserve existing environment-camera preference and fallback logic, and keep cleanup deterministic when the cashier exits.

## Migration Plan

- Update the camera scanner modal structure and status surface in `Modules/Pos/Resources/views/sell.blade.php` to support a persistent scanner session and visual guide overlay.
- Refactor `public/js/pos-camera-scanner.js` from a first-hit-close flow into a re-armable session state machine with duplicate suppression, cooldown handling, and explicit close semantics.
- Extend POS feature coverage so scan-input capability tests assert continuous-session expectations, duplicate prevention, and parity with existing resolver outcomes.
- Deploy as a frontend-only change with no backend migration.
- Rollback by reverting the modal/state-machine changes, restoring the prior first-decode-close camera session behavior.

## Open Questions

- What cooldown window feels right operationally for tablet cashiers: a very short global pause, or only per-code dedupe with near-immediate re-arm for different codes?
- Should successful scan feedback inside the scanner include the resolved product name/quantity increment when available, or stay minimal and let the cart remain the primary confirmation surface?
- Should torch control be part of this first pass, or can it remain a follow-up if camera guidance and continuous session behavior already improve reliability enough?
