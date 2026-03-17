## Context

The POS cart qty column currently mixes shared and branch-specific markup between serial/non-serial and privileged/non-privileged row rendering paths in `sell.blade.php`. The current structure works functionally, but visual density is still uneven: the spinner triplet can feel too loose, `-`/`+` buttons use inconsistent semantic coloring, and serial-row secondary controls do not always read as a clean centered stack.

This change is a UI-contract refinement only. Approval lifecycle, permissions, and serial modal APIs must stay unchanged.

## Goals / Non-Goals

**Goals:**
- Make qty controls visually compact in all row variants with a clear `[-][input][+]` rhythm.
- Standardize spinner button semantics and hover behavior (`-` danger-outline, `+` primary-outline).
- Keep serial-required rows center-aligned with one stacked qty-cell composition: spinner row, serial action row, serial chips row.
- Preserve current role and approval semantics (`Periksa`, approved token flow, direct decrease for privileged users).

**Non-Goals:**
- Changing approval request entities/endpoints/status transitions.
- Changing serial append/remove modal behavior or backend serial validation.
- Reworking cart table columns outside qty-cell visual composition.

## Decisions

### Decision 1: Replace scattered inline spacing with a compact qty-strip class contract
Use a single class-based spacing contract for qty strip rows and remove conflicting inline `gap` values in row templates.

Rationale:
- Inline gap declarations across branches caused drift and made compact tuning inconsistent.
- A single class allows one adjustment point for both serial and non-serial rows.

Alternatives considered:
- Keep branch-level inline spacing and tune each independently.
: Rejected because it reintroduces layout divergence risk.

### Decision 2: Apply semantic spinner button styling consistently by action intent
Map spinner actions to semantic outlines:
- decrease/reduce idle state: `btn-outline-danger`
- increase state: `btn-outline-primary`
- approval pending/approved states remain `warning`/`success` as today.

Rationale:
- Strengthens affordance: destructive direction uses danger, additive direction uses primary.
- Keeps existing approval-state meaning untouched.

Alternatives considered:
- Keep neutral `btn-outline-secondary` for both actions.
: Rejected because directional intent is less scannable in busy cashier flows.

### Decision 3: Serial qty cell uses a centered vertical stack with explicit rows
For serial-required rows, render qty content in a centered stack:
1. top compact spinner row,
2. serial action row,
3. serial chip row (wrapped).

Rationale:
- Matches cashier mental model: qty first, serial management next, serial evidence last.
- Eliminates side-by-side drift where serial controls can appear offset from the spinner.

Alternatives considered:
- Keep current partially wrapped side-by-side layout.
: Rejected because horizontal wrapping creates inconsistent perceived alignment per row content length.

### Decision 4: Keep approval-state mapper and event wiring unchanged
No changes to `normalizeQtyApprovalState`, approval check flow, or token execution flow beyond class/markup placement updates.

Rationale:
- Recent fixes already made approval state deterministic via fresh snapshot render.
- This change targets visual contract; touching state machinery would raise regression risk.

Alternatives considered:
- Refactor approval handlers together with layout update.
: Rejected to keep scope narrow and low-risk.

## Risks / Trade-offs

- [Risk] Pending/approved label widths (`Periksa`, `✓ qty`) can still widen the left slot compared with icon-only `-`.
  → Mitigation: keep compact row gap and center alignment so expansion is controlled and readable.

- [Risk] Stronger danger/primary styling may feel visually heavier in dense rows.
  → Mitigation: keep `btn-sm` sizing and existing corner radius; avoid larger paddings.

- [Risk] Serial chip row centering may increase cell height on high-serial lines.
  → Mitigation: preserve wrapping behavior and compact chip sizing already in place.

## Migration Plan

1. Update qty-cell template branches in `buildLineRow()` to use the compact strip/stacks consistently.
2. Update CSS classes for qty strip spacing, spinner button semantics, and serial-row centered stack alignment.
3. Validate interaction paths:
   - privileged decrease/increase,
   - non-privileged reduce request and `Periksa`/approved transitions,
   - serial action button and chip wrapping.
4. Run POS supervised-cart tests and targeted manual UI checks for serial/non-serial rows.
5. Rollback strategy: revert sell-view markup/CSS updates only (no data or API migration required).

## Open Questions

- Should the left slot keep a fixed minimum width for `Periksa`/approved states, or prioritize fully minimal spacing even with slight state-based width shifts?
