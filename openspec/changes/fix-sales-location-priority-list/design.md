## Context

The sale-location configuration screen currently left-joins every location to the active business's sale-location assignments and orders rows by the joined `position`. Locations that are disabled or have never been enabled for the active business have a null position; database null ordering can place them above valid enabled POS locations. The same page gives every rendered row a reorder control and submits every ID, while the reorder endpoint permits only IDs that already have assignments.

The existing POS resolver already consumes only enabled assignments in ascending position order. The configuration screen must express that same domain model instead of treating availability and priority as one list.

## Goals / Non-Goals

**Goals:**

- Make the active POS priority list contain exactly enabled, assigned sale locations.
- Present disabled or unassigned foreign locations as enable-first choices outside the priority list.
- Preserve a contiguous, deterministic enabled priority order after enablement and reorder operations.
- Repair or prevent owned locations lacking the required enabled assignment.
- Preserve resolver cache invalidation for every effective enabled-list mutation.

**Non-Goals:**

- Changing which users or roles may configure sale locations.
- Changing cross-business opt-in policy or enabling a location automatically for unrelated businesses.
- Changing POS stock allocation or checkout logic beyond consuming the existing resolver order.
- Adding drag-and-drop or changing the persistence schema unless implementation evidence makes it necessary.

## Decisions

### Model configuration as two collections

The controller will derive an active collection from enabled `SettingSaleLocation` assignments for the current setting, ordered by position, and a separate available collection for foreign locations that are disabled or unassigned. Only active rows have priority controls and reorder form inputs.

Rationale: an assignment's `position` has meaning only for a location that POS can currently use. Separating the collections removes null ordering ambiguity and makes the UI match server validation.

Alternative considered: keep one table and sort enabled rows before disabled rows. Rejected because disabled rows would still visually imply a priority and could accidentally be included in client-side reorder behavior.

### Reorder validates the enabled assignment set exactly

The reorder action will compare the submitted IDs with the current setting's enabled assignment IDs, reject duplicates, and update positions for that set atomically. Disabled assignments and unassigned locations are excluded from both expected and submitted IDs.

Rationale: this enforces that every submitted location has a meaningful, current priority while closing the current duplicate-ID validation gap.

Alternative considered: accept every visible location and create assignments during reorder. Rejected because reordering must not implicitly enable a foreign location.

### Enablement appends, then permits reordering

Enabling a foreign location will create or reactivate its assignment with a position after the highest enabled position for the current setting. The next page render shows it in the active list, where it can be reordered deliberately.

Rationale: it makes enablement the explicit boundary for POS eligibility and avoids giving disabled rows a phantom position.

### Enforce the owned-location assignment invariant

A location owned by the current setting must have an enabled assignment. The configuration read path and/or an explicit data repair will ensure legacy missing assignments are restored with a valid trailing position before they are represented as active and reorderable.

Rationale: the current display treats owned locations as enabled despite an absent pivot row, which creates an unsaveable list and diverges from the resolver.

## Risks / Trade-offs

- [Legacy owned locations lack assignments] → Repair them idempotently with the next enabled position and cover the state in a regression test.
- [Concurrent enable/reorder operations] → Use a database transaction and calculate/update the enabled set consistently; retain the existing cache invalidation hooks.
- [Existing disabled assignments retain historical positions] → Ignore those positions until the assignment is re-enabled; enabled positions are normalized by reorder operations.
- [Users expect a single table] → Clearly label active priority and available disabled sections so the enable-first rule is visible.

## Migration Plan

1. Deploy the UI/controller/model changes with focused feature coverage.
2. Run the idempotent repair for owned locations missing an enabled assignment, assigning valid trailing positions.
3. Verify each business's resolver output contains only enabled assignments in the displayed active-list order.
4. Rollback remains code-only; the repair is additive and preserves all existing assignments.

## Open Questions

- None. The reported enable-first workflow establishes the intended behavior.
