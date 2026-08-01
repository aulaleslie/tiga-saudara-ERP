## 1. Shared Global Filter State

- [x] 1.1 Review the existing Livewire 3 URL-state and browser-test conventions, then define aligned draft and applied global-filter properties for sales and purchase tables.
- [x] 1.2 Add explicit apply and reset actions that normalize document-date ranges, reset pagination, persist only applied values, and dispatch synchronized filter state to the matching summary-card component.
- [x] 1.3 Update global table and summary queries to consume applied business/date values while preserving lifecycle, live-balance, card, search, sort, and payment eligibility behavior.

## 2. Summary-Card State and Workspace UI

- [x] 2.1 Make the selected sales summary-card filter durable across table/filter-triggered summary refreshes while retaining composed filter behavior.
- [x] 2.2 Align sales and purchase global payment workspace shells and filter panels with grouped `Tanggal Dokumen` controls, primary `Terapkan Filter`, secondary `Reset`, and applied-filter/result feedback.
- [x] 2.3 Preserve existing domain-specific columns, payment-only actions, responsive table behavior, search submission, and normal non-global table behavior.

## 3. Verification

- [x] 3.1 Update focused sales and purchase Livewire tests for draft-versus-applied state, inclusive single/reversed date boundaries, business/date/card/search composition, reset, pagination reset, and durable sales-card selection.
- [x] 3.2 Add supported browser-level coverage for the sales global-payment page: select dates, apply, verify filtered rows and summary feedback, then reset.
- [x] 3.3 Add supported browser-level coverage for the purchase global-payment page: select dates, apply, verify filtered rows and summary feedback, then reset.
- [x] 3.4 Run the focused global sales and purchase payment test suites and the relevant browser tests; resolve regressions without changing payment allocation or document eligibility semantics.
