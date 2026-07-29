## 1. Assignment invariants and priority persistence

- [x] 1.1 Add an idempotent repair path for current-setting owned locations that lack an enabled `SettingSaleLocation` assignment, assigning the next enabled priority position.
- [x] 1.2 Update assignment creation and re-enablement behavior so the next position is calculated only from enabled assignments and a re-enabled location is appended to the enabled priority list.
- [x] 1.3 Harden the reorder action to accept exactly the unique enabled assignment IDs, update their contiguous positions in one transaction, and invalidate the affected resolver cache.

## 2. Configuration screen behavior

- [x] 2.1 Refactor the sale-location configuration query into enabled active-priority and disabled/unassigned available collections for the current setting.
- [x] 2.2 Render active locations as the only sortable/reorderable list and submit only their IDs.
- [x] 2.3 Render disabled or unassigned foreign locations after the active list with enable-only controls and clear priority-state labeling.

## 3. Regression coverage

- [x] 3.1 Add feature coverage proving unassigned and disabled foreign locations appear outside the active list and cannot affect its saved order.
- [x] 3.2 Add feature coverage proving enablement appends a foreign location to the enabled list and allows a subsequent reorder.
- [x] 3.3 Add feature coverage for missing owned assignments, duplicate/invalid reorder payloads, atomic rejection, and resolver cache invalidation.

## 4. Verification

- [x] 4.1 Run the focused Setting sale-location configuration and cache-consistency test suites.
- [x] 4.2 Run the relevant POS resolver/allocation tests to confirm enabled priority order remains unchanged for downstream POS flows.
