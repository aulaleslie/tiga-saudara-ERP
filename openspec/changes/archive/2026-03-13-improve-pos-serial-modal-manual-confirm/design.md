## Context

The POS serial modal on `/pos/sell` is optimized for scanner-first flow: serial append is triggered by Enter on the input field, and the footer button closes the modal. In manual typing flow, cashiers often click the footer action (`Selesai`) expecting submission, which closes modal without appending and creates silent user error. The change must preserve existing backend contracts and scanner burst performance while making manual submission explicit.

## Goals / Non-Goals

**Goals:**
- Provide an explicit click-based submit affordance for manual serial input in the serial modal.
- Keep Enter/scanner submission as a first-class fast path.
- Make close action semantics unambiguous so users do not confuse close with submit.
- Reuse existing append/remove API endpoints and error contracts.

**Non-Goals:**
- Changing serial allocation domain rules, validation rules, or API payload structure.
- Redesigning broader POS cart layout outside serial modal controls.
- Introducing new modal framework dependencies.

## Decisions

1. Add a dedicated submit control near the serial input (`Masukkan`) that invokes the same append handler used by Enter.
Rationale: keyboard and click paths must be behaviorally identical to reduce divergence and bug surface.
Alternative considered: keep Enter-only flow and improve label copy. Rejected because copy alone does not provide an actionable click path for manual users.

2. Keep footer button as close-only action and relabel to close-oriented wording (`Tutup`) to avoid implied submission.
Rationale: explicit semantic distinction reduces mistaken assumptions.
Alternative considered: making footer button context-sensitive (submit if input has value, else close). Rejected due to ambiguous behavior and higher cognitive load.

3. Add concise helper text near input clarifying submission options (Enter or click submit).
Rationale: supports first-time discoverability without blocking scanner speed.
Alternative considered: rely on placeholder text only. Rejected because placeholders disappear during typing and are weaker as guidance.

4. Keep append status feedback in existing status area and preserve modal-open-on-success burst behavior.
Rationale: current status pattern already communicates success/failure with minimal layout impact.
Alternative considered: toast-only feedback. Rejected because in-modal status is closer to user focus and already implemented.

## Risks / Trade-offs

- [Button clutter in compact modal] → Keep button sizing compact and position adjacent to input group.
- [Duplicate submissions from rapid Enter+click] → Disable submit interaction during in-flight append request and re-enable after response.
- [Label change confusion for existing users] → Keep wording simple and consistent with other modal close actions (`Tutup`).
- [Behavior drift between Enter and click paths] → Use one shared append function for both triggers.

## Migration Plan

- Update serial modal UI and event wiring in `Modules/Pos/Resources/views/sell.blade.php`.
- Add/adjust POS UI behavior tests for manual click submit parity with Enter.
- Deploy as normal frontend/template change; no database migration.
- Rollback by reverting this change set if needed.

## Open Questions

- Should manual submit use `Masukkan` or `Tambah Serial` label to align best with cashier vocabulary?
- Should helper text be always visible or only shown when serial input is focused?
