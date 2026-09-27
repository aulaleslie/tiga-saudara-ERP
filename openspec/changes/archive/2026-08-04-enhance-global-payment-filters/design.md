## Context

Global purchase and sales payment pages currently use parallel Livewire table and summary-card components. Each workspace has a draft/apply/reset filter lifecycle, URL-backed applied state, a single business ID, and a document-date range. The summary cards receive applied values through Livewire events. The list query and card queries must remain aligned.

The change adds a due-date range and replaces the single business ID with a selection of business IDs. It also addresses a reset UX defect: although the query is reset, visible controls do not always clearly return to the unfiltered state. Summary cards remain above the filter panel at the user's request.

## Goals / Non-Goals

**Goals:**

- Filter global purchase and sales payment lists and summary cards by any combination of selected businesses, document dates, and due dates.
- Keep results unchanged until the user explicitly applies draft filter values.
- Reliably synchronize the reset action's visible form state, applied URL state, table results, summary-card totals, and feedback badges.
- Provide a responsive, easily scannable panel with separate labeled document-date and due-date ranges.

**Non-Goals:**

- Add business-selector search, new payment statuses, new permissions, or database migrations.
- Change global-list eligibility; fully paid documents remain listable and can match a due-date filter.
- Move or redesign the existing summary cards.

## Decisions

### Use arrays for business filters and keep an empty array as "all businesses"

Both tables, summary cards, URL state, and filter-change events will use a `globalBusinessFilters` array. An empty array omits the business predicate; a non-empty array uses an `IN` predicate. This is unambiguous for multi-select and preserves the present default behavior.

Alternative considered: retain a scalar business filter and add an "all" sentinel. This cannot represent multiple businesses without an additional incompatible state shape.

### Treat document date and due date as independent inclusive ranges

Each date range has separately optional from/to boundaries. A supplied from boundary applies `>=`; a supplied to boundary applies `<=`; both ranges combine with AND. When both boundaries of the same range are reversed, normalize them before applying and render the normalized values. Documents with null due dates do not satisfy a supplied due-date boundary.

Alternative considered: make due date a payment-status-only filter. That would hide paid documents and conflicts with the agreed operational use case.

### Preserve explicit draft/application semantics

Draft inputs do not query until `Terapkan Filter` is selected. Applying copies every draft property into URL-backed applied state, resets pagination, and emits one event containing all effective filter values. Summary cards consume exactly that event payload, avoiding a table/card mismatch.

### Make reset a visible-state reset, not only a query reset

`Reset semua filter` clears both draft and applied state for business selections and both date ranges, clears the URL, resets pagination, emits an all-empty payload, and removes active-filter feedback. The multi-select control must be rendered from the draft array and explicitly cleared/reinitialized if its UI implementation maintains client-side selection state; a reset-version key is an acceptable fallback.

### Use a responsive grouped filter panel with no selector search

Keep summary cards where they are. Below them, display a labeled multi-business selector and two clearly grouped date ranges with `Dari`/`Hingga` labels. On wide displays, date groups may share a row; on narrow displays, they stack. The panel uses existing Bootstrap/CoreUI-compatible form styles and plain multi-select/checkbox behavior without search.

## Risks / Trade-offs

- [Table and summary-card predicates drift] → Extract or consistently apply the same three filter dimensions in each existing query, with feature tests asserting matching results/totals.
- [A multi-select's client-side display remains selected after reset] → Bind it to the draft array and test visual/server reset; use a reset-version key only when needed to recreate the control.
- [URL serialization of arrays changes existing shared links] → Accept the new array parameter while treating legacy scalar input as a compatibility input where practical; empty selection remains the no-filter default.
- [More filter inputs make the panel crowded] → Use grouped labels, responsive columns, and text-labeled reset rather than an icon-only reset action.

## Migration Plan

No schema or data migration is required. Deploy the component/view/test change together. Existing pages without filter URL parameters continue to show all eligible documents. Rollback consists of reverting the application code; persisted documents are unaffected.

## Open Questions

None.
