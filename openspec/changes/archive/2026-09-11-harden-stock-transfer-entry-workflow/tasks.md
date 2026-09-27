## 1. Schema and Domain State

- [x] 1.1 Add a MySQL/MariaDB- and SQLite-compatible migration that makes `transfers.destination_location_id` nullable while preserving its foreign key/index, and adds nullable `stock_condition` storage for `GOOD` and `BREAKAGE`.
- [x] 1.2 Classify only unambiguous historical rows into a stock condition, leave mixed-condition history untouched, and implement a rollback guard that never silently deletes destination-less drafts.
- [x] 1.3 Add transfer stock-condition constants/casts/helpers and make destination-dependent model helpers, relationships, list/detail projections, and lifecycle guards null-safe.
- [x] 1.4 Extend `TransferFormState`, line mapping, and edit hydration to carry one explicit transfer condition and preserve readable historical mixed-condition records without treating them as valid new drafts.

## 2. Draft and Submission Boundaries

- [x] 2.1 Refactor `TransferDraftService` so new records save atomically as `DRAFT`, destination is optional, and any supplied destination is authoritatively validated as active, distinct, permitted, and consignment-compatible.
- [x] 2.2 Enforce origin ownership/activity, selected condition, non-empty valid rows, condition-compatible quantity buckets, and condition-compatible serials during every draft create/update without trusting browser snapshots.
- [x] 2.3 Harden draft submission to lock the transfer, require edit authority and origin tenancy, require a valid destination, reload and revalidate products/stock/conversions/serials, and atomically transition only a valid unchanged draft to `PENDING`.
- [x] 2.4 Replace ambiguous action-string handling with explicit `saveDraft` and `submitForApproval` form/service commands, including distinct Bahasa Indonesia validation and success feedback.
- [x] 2.5 Converge retained resource-controller request paths on the same draft/submission contracts or reject unsupported legacy payloads, removing the independent immediate-`PENDING` behavior.

## 3. Searchable Location Selection

- [x] 3.1 Extend the stock-opname `LocationSearchDropdown` with backward-compatible opt-in configuration for active-only scope, cross-business lookup, excluded IDs, business-aware labels, field-specific names, and targeted selection events.
- [x] 3.2 Validate selected dropdown IDs against the configured query scope so crafted inactive, excluded, or tenant-ineligible values cannot be accepted by the component.
- [x] 3.3 Replace both transfer location loaders with the shared searchable dropdown: tenant-owned origins and active cross-business destinations excluding the selected origin.
- [x] 3.4 Make an actual origin change clear destination and rows, while destination selection/change/clear preserves all product and serial rows.

## 4. Form-wide Mode and Product Entry

- [x] 4.1 Move good/breakage mode ownership into `TransferStockForm`, hydrate it from persisted state, and pass it reactively to product search and table components.
- [x] 4.2 Clear rows, serial selections, row errors, and search state only when mode actually changes; preserve origin and destination selections across the mode reset.
- [x] 4.3 Render product search/scanning disabled with Bahasa Indonesia guidance until a valid origin is selected, and reject direct Livewire search, scan, serial, and row calls without a valid tenant-owned origin.
- [x] 4.4 Adapt the adjustment/breakage searchable and scannable interaction conventions, including deterministic duplicate handling, rapid-scan serialization, feedback, and scanner focus restoration, while retaining `TransferScanResolverService`.
- [x] 4.5 Restrict good mode to saleable stock/serials and breakage mode to broken stock/available-broken serials across search, scan, row allocation, mapping, draft save, and submission.
- [x] 4.6 Update the transfer form actions and presentation so users can save a draft without destination and can submit only a complete draft for approval.

## 5. Focused Verification and Handoff

- [x] 5.1 Add focused schema tests for nullable destination, stock-condition storage, foreign-key behavior, historical unambiguous classification, and mixed-history preservation on SQLite-compatible migrations.
- [x] 5.2 Add focused draft/lifecycle tests for saving without destination, validating an optional destination, rejecting incomplete/stale submission without mutation, atomic successful submission, tenant authorization, revision/concurrency guards, and legacy route convergence.
- [x] 5.3 Add focused Livewire tests proving product entry is origin-gated, origin change resets destination and rows, mode change resets rows only on an actual change, destination changes preserve rows, and editable drafts hydrate destination/mode/rows correctly.
- [x] 5.4 Add focused dropdown tests for existing stock-opname defaults, active tenant-scoped origin options, cross-business business-aware destination options, origin exclusion, and rejection of crafted out-of-scope selections.
- [x] 5.5 Add focused product-entry tests for good/breakage filtering, mixed-condition rejection, duplicate and rapid scans, serial condition enforcement, error feedback, and scanner-focus events; run only affected transfer, dropdown, allocation, and serial test groups.
- [x] 5.6 Prepare a concise manual browser checklist for a human covering draft without destination, later submission, searchable locations, destination exclusion, origin/mode table resets, destination row preservation, good/breakage entry, and edit hydration.
