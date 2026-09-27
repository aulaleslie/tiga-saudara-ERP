## Context

The POS sell shell already has a shared scan resolver flow (`executeScanResolve`) used by Enter and helper button triggers, while the camera slot is currently a reserved disabled control. The backend resolver endpoint (`/pos/sell/search/resolve`) is stable and validates `q` with a max length of 255.

The target behavior is cross-device camera scanning with deterministic cashier UX: clicking camera should open available camera (rear-preferred on mobile/tablet, webcam fallback on laptop), decode one value, mirror that value into the existing barcode/serial input, and reuse the current resolver behavior.

Constraints:
- Preserve existing resolver API and max-length guard.
- Do not add payload normalization/parsing logic for QR payload variants.
- Keep scan results reviewable by leaving not-found decoded value in input.
- Keep operational flow quick by ending each camera session after first decode outcome.

## Goals / Non-Goals

**Goals:**
- Activate camera action in the existing POS scan rail.
- Support broad symbology decoding for unknown brand codes (1D + 2D).
- Mirror raw decoded value into existing scan input before resolver call.
- Use existing resolver flow so camera, Enter, and helper remain behaviorally aligned.
- Close scanner and release media stream after first decode attempt result.

**Non-Goals:**
- Redesign product/serial resolver matching logic.
- Parse or normalize QR payload structures (URL/JSON/GS1 expansion).
- Introduce continuous multi-item camera scanning in one open session.
- Expand backend `q` validation beyond max 255 for this change.

## Decisions

### Decision 1: Use `@zxing/browser` with explicit multi-format allowlist
Camera decoding will use `@zxing/browser` and enable an explicit format set covering retail 1D and occasional 2D usage.

Rationale:
- Handles unknown scanner source brands with one decoder path.
- Supports QR and common linear codes without separate libraries.
- Keeps implementation inside frontend POS shell without backend format branching.

Alternatives considered:
- `quagga2`: strong 1D support but weaker fit for mixed 1D + QR requirement.
- Native `BarcodeDetector` only: compatibility gaps make behavior less predictable across devices.

### Decision 2: Rear-camera preference with fallback camera selection
Camera startup requests `facingMode: { ideal: 'environment' }` and falls back to default available camera when unavailable.

Rationale:
- Matches cashier expectation on phones/tablets.
- Keeps laptop flow functional without device-specific branching.

Alternatives considered:
- Hard-require environment camera: fails on laptops.
- Always default camera: degrades tablet/phone ergonomics.

### Decision 3: Single-hit camera session lifecycle
After the first successful decode event, scanner flow is locked, payload is processed once, and scanner modal closes regardless of resolver result type.

Rationale:
- Prevents duplicate adds from repeated frame detections.
- Aligns with user-selected policy: auto-close and close-after-first-hit.

Alternatives considered:
- Continuous scan mode: faster for batch use but higher accidental duplicate risk and larger UX surface.

### Decision 4: Raw payload contract with frontend max-length gate
Decoded value is used as-is (trim only), mirrored into `#pos-shell-search`, and submitted to resolver only if length <= 255.

Rationale:
- Matches explicit decision to avoid normalization.
- Preserves backend API contract while still giving users full raw value visibility.

Alternatives considered:
- Backend limit expansion: unnecessary for current agreed workflow.
- Client normalization/parsing rules: rejected to avoid hidden data mutation.

### Decision 5: Resolver parity via existing `executeScanResolve`
Camera path will call the existing shared resolver function rather than a camera-specific request flow.

Rationale:
- Prevents trigger parity drift.
- Reuses existing status/side-effect semantics for add-to-cart and serial handling.

Alternatives considered:
- Dedicated camera resolver handler: duplicates behavior and increases maintenance risk.

## Risks / Trade-offs

- [Broad format set increases false-positive decode chance] -> Mitigation: single-hit lock and clear status feedback before resolver call.
- [Camera permission denial blocks intended fast path] -> Mitigation: explicit error state and immediate fallback to manual input field.
- [Long QR payload cannot be resolved under 255 limit] -> Mitigation: keep value in input, show warning, and let cashier manually edit.
- [Device/browser camera behavior variance] -> Mitigation: rear-camera ideal constraint with deterministic fallback and tested stop-stream cleanup.

## Migration Plan

1. Add camera scanner dependency and bundle it in current frontend pipeline.
2. Replace reserved camera button with active trigger and add scanner modal UI.
3. Implement camera lifecycle (open, decode once, stop stream, close modal) and status states.
4. Wire decoded raw value into existing input and resolver call path.
5. Add/update feature-level view tests for camera control presence and updated layout contract.
6. Perform manual verification on laptop webcam and mobile/tablet rear camera.

Rollback strategy:
- Revert POS sell view/scripts and remove scanner dependency usage, returning camera action to disabled placeholder behavior.

## Open Questions

- Should scan format telemetry (detected format name + success/fail outcome) be captured for future tuning, or deferred entirely to keep scope minimal?
