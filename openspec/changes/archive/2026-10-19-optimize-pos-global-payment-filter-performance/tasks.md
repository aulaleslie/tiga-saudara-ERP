# Tasks

## 1. Focused baseline

- [x] 1.1 Create a repeatable isolated benchmark recipe or focused harness for small and larger POS datasets, including ordinary no-return and mixed split/replacement records; verify repeated runs report environment, fixture size, SQL count/time, elapsed time, projection count, and peak memory without modifying production data.
- [x] 1.2 Record the unmodified baseline for no filter, business/date, cashier/terminal, payment status, due date, and card selection; verify the record distinguishes table work, summary work, and full event-chain timing where available.

## 2. Batch replacement-sale discovery

- [x] 2.1 Implement operation-local batch preparation and resolver consumption for completed non-reversed return replacement Sales, including explicit empty results and the existing single-record fallback; verify focused resolver/projection tests compare financial output, ordering, and diagnostics for inline, split, missing/duplicate mappings, archived Sales, and checkout-or-transaction return association.
- [x] 2.2 Use prepared replacement data in table and summary projection collections; verify a focused query-growth test shows replacement-discovery queries remain bounded per batch as transaction count grows and consecutive operations do not reuse stale context.

## 3. Reuse table projections

- [x] 3.1 Reuse the projection map from pre-pagination filtering for page rows and prepare only page rows when no projection filter is active; verify focused table tests preserve filtered totals, intersection, page navigation, tied ordering, and payment-action eligibility while each candidate is projected at most once per render.
- [x] 3.2 Restrict presentation-only eager loads to visible rows where feasible; verify focused table/search rendering tests preserve business, customer, cashier, terminal, note, receipt, and action output.

## 4. Avoid redundant summary calculation

- [x] 4.1 Update card selection/highlighting without recalculating unchanged summary totals, preserving table-filter dispatch and completion behavior; verify focused select/deselect, URL-state, loading-overlay, and completion-event tests.
- [x] 4.2 Notify summary cards only when summary inputs change or an explicit reset refresh is required; verify focused tests distinguish table-only filters from business/date/due-date changes, retain current summary scope, and show fresh financial results after reset or workspace reload following a committed payment change.

## 5. Focused integration evidence

- [x] 5.1 Run only affected POS resolver/projection, table/search, authorization/loading, and replacement-return tests using `php artisan test` class or method filters; record exact commands and outcomes, including applicable credit, invalidated-payment, returned-zero, and recent-payment boundary scenarios. Do not run or require the full suite.
- [x] 5.2 Repeat the baseline with identical fixtures and sampling, document before/after results and residual full-scan limitations, and perform the workspace filter/card smoke check if a browser environment is available; verify no migration, index, schema, or payment-write workflow changes appear in the diff.
