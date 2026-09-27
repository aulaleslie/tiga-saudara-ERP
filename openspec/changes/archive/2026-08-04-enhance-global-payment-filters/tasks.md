## 1. Shared Global Filter State

- [x] 1.1 Replace the single global business filter state with URL-backed draft and applied business-ID arrays in the purchase and sales global table components, preserving an empty selection as all businesses.
- [x] 1.2 Add URL-backed draft and applied due-date from/to state, independent date-range normalization, and complete apply/reset event payloads to both global table components.
- [x] 1.3 Update global table queries to compose selected-business, document-date, and due-date predicates with existing eligible-status, live-balance, summary-card, search, sort, and pagination behavior.

## 2. Summary and Filter UI

- [x] 2.1 Update purchase and sales summary-card components to receive the expanded applied filter event and apply identical selected-business, document-date, and due-date predicates to all card totals.
- [x] 2.2 Redesign both global filter panels with a non-searchable multi-business selector, separately labeled `Tanggal Dokumen` and `Tanggal Jatuh Tempo` ranges, responsive layout, and text-labeled `Reset semua filter`, while keeping summary cards in their existing position.
- [x] 2.3 Render unambiguous applied-filter feedback for multiple businesses and each date range, and ensure reset visibly clears every selector/input/badge as well as the underlying query state.

## 3. Verification

- [x] 3.1 Add focused purchase global-payment Livewire tests for no/single/multiple business selection, inclusive and one-sided due-date filtering, combined filters, fully paid results, null due dates, and reversed ranges.
- [x] 3.2 Add equivalent focused sales global-payment Livewire tests, including the `due_date`-backed sales payment-due-date display/accessor behavior.
- [x] 3.3 Add tests that apply/reset state, URL restoration, selected summary-card persistence, and summary-card totals remain synchronized with table results for both workspaces.
- [x] 3.4 Run the focused purchase and sales global-payment test suites and resolve regressions.
