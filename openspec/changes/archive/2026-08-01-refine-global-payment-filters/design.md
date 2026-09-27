## Context

The global sales and purchase payment workspaces each render independent Livewire summary-card and table components. Their tables already constrain the `date` column using the selected document-date boundaries, but the controls use immediate Livewire updates and provide only a Reset button. Users therefore receive no explicit confirmation that their selected values were applied. The independently refreshed summary-card component also owns its selected-card indication in Alpine-local state, which can be lost after it receives the table's filter-refresh event.

The implementation must preserve the existing cross-setting eligibility, canonical live-balance calculation, payment permissions, search, sorting, pagination, and payment allocation behavior. It uses Laravel 10, Livewire 3, and the established Bootstrap/CoreUI visual language.

## Goals / Non-Goals

**Goals:**

- Give both global payment workspaces one explicit, reliable apply interaction for business and document-date filters.
- Keep the table and summary cards synchronized to the same applied filter state.
- Make applied filters and selected summary-card state visible after each Livewire refresh.
- Verify browser interaction from selecting dates through applying and resetting filters.

**Non-Goals:**

- Changing which sales or purchases are globally eligible, or what date column is filtered.
- Altering payment allocation, payment data, lifecycle transitions, authorization, or database schema.
- Turning the global payment workspace into a payment-date report or adding exports.

## Decisions

### 1. Separate draft controls from applied filter state

Business and document-date inputs will bind to draft state. `Terapkan Filter` will validate and normalize the range, copy it into applied state, reset pagination, and dispatch one synchronized filter event to the summary cards. The query, query-string state, active-filter display, and summary calculations will use only applied state.

This gives the user a clear transaction boundary and avoids an incomplete or intermediate date range changing the table while a picker is still being used. It also makes test behavior mirror the intended browser interaction.

Alternative considered: retain `wire:model.live` and add only a button. Rejected because the button would be cosmetic and the ambiguous automatic updates would remain.

### 2. Retain document-date semantics and independent boundaries

The filters continue to target each document header's `date` field inclusively. A business selection, from date, and to date compose with lifecycle, summary-card, search, and pagination conditions. Either date boundary remains optional; if both are supplied in reverse order, apply normalizes them and reflects the normalized values back to the controls.

Alternative considered: filter by payment date. Rejected because one document may have several payments and credit settlement; that is reporting behavior outside this workspace.

### 3. Use one shared visual structure for the two workspaces

Both global lists will render summary cards followed by a named filter panel containing Business and grouped `Tanggal Dokumen` inputs, primary `Terapkan Filter`, secondary `Reset`, and concise applied-state/result feedback. The table search remains distinct because it has its own submit action. Existing domain-specific columns remain unchanged, while page hierarchy, controls, spacing, and action priority align.

Alternative considered: extract a new reusable generic Livewire filter component. Rejected for this narrow two-screen refinement; matching component interfaces and a shared Blade partial are lower-risk than introducing a new component contract.

### 4. Make the selected summary filter server-owned or durable across refreshes

The selected outstanding, overdue, or paid state will survive summary-card rerenders and remain visibly selected while the same card filter continues to constrain the table. Clearing a card selection restores the unrefined eligible list without clearing applied business/date/search filters.

Alternative considered: preserve the existing Alpine-only state. Rejected because a sibling component refresh has no durable source from which Alpine can restore the selection.

### 5. Cover interaction, not only query methods

Keep focused Livewire tests for inclusive boundaries, composition, reset, and reversed ranges. Add browser-level coverage for setting date controls, clicking `Terapkan Filter`, observing only in-range rows and updated summaries, and resetting to the unfiltered state for both sales and purchases.

## Risks / Trade-offs

- [Draft and applied state can drift] → Render feedback solely from applied state and synchronize draft state after apply/reset.
- [Sibling Livewire events can refresh summary cards but leave table/card state inconsistent] → Carry all applied filter and selected-card values in explicit event/state contracts and test the composed state.
- [Browser test setup may be unavailable or slow] → Use the repository's supported browser-test mechanism; if absent, add the smallest supported integration test that exercises the Livewire DOM action rather than relying only on direct property assignment.
- [Duplicated sales/purchase markup can diverge again] → Use aligned naming, layout, and a shared partial where it materially reduces duplication; retain domain columns in their existing views.

## Migration Plan

No data migration or API migration is required. Deploy the UI/component change with its focused tests. Rollback is code-only: restoring the current views and Livewire state leaves payment records and document data unchanged.

## Open Questions

- None. The filter targets document date, not payment date, and the user-facing action is `Terapkan Filter`.
