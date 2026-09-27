## Context

Issue A on `/pos/sell` is caused by three UI/script mismatches in the serial flow:
- Serial action button is icon-only and uses Font Awesome classes, while the app layout currently loads Bootstrap Icons.
- Serial modal product context is read from `.product-name`, but cart rows render the product label with `.name`.
- Serial removal inside the modal reuses a cart-table click path that expects `tr[data-line-id]`, which is not present in modal markup.

The fix should be limited to POS sell serial UI behavior, without changing checkout domain logic or data models.

## Goals / Non-Goals

**Goals:**
- Ensure serial action control on serial-required lines is always visibly understandable to cashiers.
- Ensure opening serial modal always shows the correct product context.
- Ensure modal serial-chip remove action consistently deletes the target serial for the active line.
- Keep API contracts unchanged (`/serials/append`, `/serials/{serial}` delete).

**Non-Goals:**
- Redesigning overall POS cart layout.
- Changing backend serial validation/allocation logic.
- Introducing new icon libraries or global UI dependency migrations.

## Decisions

1. Use Bootstrap Icons-compatible affordance with visible fallback text for serial action button.
Rationale: bootstrap-icons is already loaded in shared layout; this removes hidden dependency on Font Awesome and prevents blank icon-only controls.
Alternative considered: load Font Awesome globally. Rejected to avoid broader dependency and inconsistent icon systems.

2. Bind product context directly from row rendering contract instead of fragile selector assumptions.
Rationale: using a stable source (for example a `data-product-name` on `.js-serial-add`, or a selector aligned to existing `.name`) prevents silent breakage when class names differ.
Alternative considered: keep `.product-name` and update markup class only. Rejected because it remains brittle for future DOM refactors.

3. Handle modal serial remove events in modal scope using active line context (`currentSerialLineId`).
Rationale: modal chips are outside cart row DOM, so removal must not depend on `tr[data-line-id]`; event delegation on modal list with shared delete helper is the most reliable path.
Alternative considered: force modal markup to include hidden row-like wrappers with line-id. Rejected as unnecessary coupling to table structure.

4. Preserve current success/error status messaging behavior while unifying remove logic.
Rationale: cashiers already rely on existing status line patterns; reusing one helper for remove API calls reduces divergent behavior between cart chips and modal chips.

## Risks / Trade-offs

- [Wider serial button footprint] → Use compact button sizing and short label, and keep responsive wrapping in serial qty cell.
- [Two event entry points for remove action] → Centralize API delete + render refresh in one helper called by both cart and modal handlers.
- [Potential stale active line reference if cart refreshes mid-modal] → Validate `currentSerialLineId` before delete and show actionable error if line no longer exists.

## Migration Plan

- Implement view-script updates in `Modules/Pos/Resources/views/sell.blade.php`.
- Execute targeted POS serial flow validation (manual checklist and any existing POS UI/feature tests).
- Deploy as standard frontend asset/template update (no DB migration).
- Rollback strategy: revert the view changes for this change set only.

## Open Questions

- Should the serial action affordance use icon+text permanently, or icon with explicit tooltip + screen-reader text only? Default for this change is icon+short visible text for clarity.
