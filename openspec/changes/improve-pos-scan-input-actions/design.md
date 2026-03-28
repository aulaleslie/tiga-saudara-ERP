## Context

The POS sell shell currently treats Enter on the scan input as the primary trigger for scan resolution. In tablet browser usage, software keyboards often expose a Next action that advances focus instead of producing a reliable Enter signal for this field. This causes inconsistent scan execution for manual/mobile users.

The same search area already contains another action ("Cari Produk"), and future roadmap includes adding a camera-scan action from the tablet browser. Without a structured action layout, each new action risks making the header crowded, visually inconsistent, and harder to use during checkout.

Constraints:
- Preserve scanner-speed workflow for keyboard/scanner users.
- Avoid backend contract changes for scan resolve.
- Keep POS shell layout compact and professional in landscape tablet use.

## Goals / Non-Goals

**Goals:**
- Provide deterministic scan execution via explicit helper action in addition to Enter.
- Establish a tidy, professional action-rail layout for scan-related controls.
- Ensure helper button and Enter path are behaviorally equivalent in resolver call and status feedback.
- Keep a stable, reserved placement for future camera action to avoid later redesign.

**Non-Goals:**
- Implement tablet camera scanning in this change.
- Redesign the wider POS shell grid or cart/customer/payment modules.
- Change scan resolver endpoint contracts or product/serial matching logic.

## Decisions

### Decision 1: Introduce an explicit scan helper action as first-class trigger
The scan area will include a dedicated helper button that triggers the same resolver flow used by keyboard Enter.

Rationale:
- Mobile keyboards are inconsistent for Enter/Next semantics.
- A tap target provides deterministic behavior across devices.
- Keeps scanner and manual input paths unified.

Alternatives considered:
- Enter-only with keyboard hint improvements only: rejected because hints are advisory and not consistently enforced by keyboard apps.
- Auto-resolve on blur: rejected due to accidental triggers during focus changes.

### Decision 2: Standardize scan area to input + action rail pattern
The layout will use one primary input region and a dedicated action rail that can host multiple related actions with clear priority.

Rationale:
- Prevents horizontal crowding as actions grow.
- Creates predictable placement for current and future controls.
- Keeps professional visual hierarchy in dense cashier UI.

Alternatives considered:
- Inline many buttons on one row: rejected due to poor readability and reduced tap comfort in tablet landscape.
- Separate each action into different cards: rejected because it increases travel distance and slows cashier flow.

### Decision 3: Reserve future camera slot now, without enabling camera behavior
A camera action slot will be represented in layout contract now (visible placeholder or disabled control), while camera integration remains out of scope.

Rationale:
- Avoids future structural refactor when camera support is implemented.
- Lets UX and QA validate stable spacing/order early.

Alternatives considered:
- Add camera control later with ad-hoc placement: rejected due to high risk of inconsistent UI and regressions in compact layouts.

### Decision 4: Preserve existing backend APIs and resolver semantics
This change remains frontend interaction and layout focused; existing `/pos/sell/search/resolve` API remains unchanged.

Rationale:
- Problem is trigger reliability and UI structure, not resolver capability.
- Limits risk and keeps rollout straightforward.

Alternatives considered:
- Add new scan endpoints per trigger type: rejected as unnecessary complexity.

## Risks / Trade-offs

- [Extra visible controls could increase visual noise] -> Mitigation: enforce action hierarchy (primary vs secondary/tertiary) and consistent button sizing.
- [Trigger parity drift between Enter and helper button over time] -> Mitigation: route both triggers through one shared resolver function and add parity tests.
- [Reserved camera slot may feel inactive before camera launch] -> Mitigation: use clear disabled/coming-soon presentation and keep it visually subordinate.
- [Small-screen landscape density pressure] -> Mitigation: define responsive action-rail behavior that preserves tap targets and ordering.

## Migration Plan

1. Update scan area markup and styles to action-rail structure.
2. Wire helper action to shared scan resolver path.
3. Validate Enter/helper parity and status messaging behavior.
4. Deploy with no backend migration; rollback is frontend-only by reverting sell shell view changes.

## Open Questions

- Should the reserved camera slot be visible-disabled or hidden behind a feature flag until camera work starts?
- Should helper button label prioritize cashier language as "Scan" or "Cari Cepat" for best operational clarity?
