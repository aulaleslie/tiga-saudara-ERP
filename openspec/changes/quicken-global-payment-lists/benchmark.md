# Benchmark: quicken-global-payment-lists

## 1. Isolated fixture (SQLite, PHPUnit)

- Driver: SQLite in-memory test DB (`php artisan test`), Laravel 10, Livewire 3.0.5.
- Date: 2026-11-14 (frozen `Carbon::setTestNow('2026-11-14 12:00:00')`).
- Driver/test: `Modules/Sale/Tests/Feature/GlobalSaleSummaryBenchmarkTest.php`.
- Fixture: one business, one customer, N eligible DISPATCHED Sales (total 1000) each with one active payment (1/3 fully paid, rest 250); due dates spread −3..+3 days, document dates over 40 days.
- Phases: `summary` = fresh global `SaleSummaryCards` mount + (if present) `loadSummary()` + reading outstanding/overdue/recent accessors; `selection` = `toggleCardFilter('unpaid')` on an already-loaded component + reading all three accessors (what a render does).
- One warmup, three samples, median by elapsed reported. Page size not applicable (summary only; the table phase is unchanged apart from one extra correlated reduction column and is covered by query-count tests).

### Baseline (before change)

Command: `BENCH_BASELINE=1 php artisan test --filter=GlobalSaleSummaryBenchmarkTest`

| Sales | Phase | Queries | SQL ms | Elapsed ms | Peak MB |
|---|---|---|---|---|---|
| 10 | summary | 43 | 1.24 | 4.46 | 204.35 |
| 10 | selection | 43 | 1.23 | 4.36 | 204.34 |
| 100 | summary | 473 | 10.31 | 40.58 | 204.73 |
| 100 | selection | 473 | 10.18 | 39.72 | 205.14 |

Confirms the baseline exercises per-Sale balance reads (≈ 4.3 extra queries per positive-due Sale: credits, incoming, outgoing transfers and return reduction per hydrated Sale, for both outstanding and overdue) and that card selection recalculates everything.

## 2. Local MySQL (read-only, existing dataset)

- Dataset: local `tiga_saudara` MySQL, 215,704 Sales at measurement time (proposal recorded 215,558).
- Method: scratch script booting the app, `SET SESSION TRANSACTION READ ONLY` + transaction rolled back; component accessors called directly (no HTTP/Livewire request lifecycle, no browser). These are SQL/PHP timings only, single run, not browser latency.
- Scopes: `default` (all businesses, no dates) and `business+date` (lowest setting id, document date 2026-01-01..2026-11-14).

### Baseline (before change)

| Scope | Card | Queries | SQL ms | Elapsed ms | Result |
|---|---|---|---|---|---|
| default | outstanding | 586 | 1934.7 | 1982.7 | 117 / 615,092,206.99 |
| default | overdue | 516 | 1959.1 | 1997.2 | 103 / 377,785,207 |
| default | recent | 1 | 197.8 | 198.7 | 3904 / 3,959,918,748 |
| business+date | outstanding | 56 | 163.7 | 167.9 | 11 / 29,576,502.83 |
| business+date | overdue | 46 | 150.9 | 154.6 | 9 / 28,869,000 |
| business+date | recent | 1 | 33.8 | 34.3 | 221 / 565,589,000 |

Baseline EXPLAIN (outstanding filter): PRIMARY `sales` type=ALL rows≈214,492 Using where, with dependent subqueries per Sale on `sale_payments` (ref sale_payments_sale_status_idx), `spca` (type=ALL), `popt_in`/`popt_out` (ref), `sra` (ref sr_adjustments_sale_idx).

## 3. After implementation

### Isolated fixture (SQLite) — same test, workload, boundaries

Command: `php artisan test --filter=GlobalSaleSummaryBenchmarkTest` (now asserts constant query count and zero selection queries).

| Sales | Phase | Queries | SQL ms | Elapsed ms | Peak MB |
|---|---|---|---|---|---|
| 10 | summary | 2 | 0.50 | 1.73 | 226.76 |
| 10 | selection | 0 | 0.00 | 0.05 | 226.75 |
| 100 | summary | 2 | 0.90 | 2.13 | 227.08 |
| 100 | selection | 0 | 0.00 | 0.05 | 227.08 |

`summary` now includes `loadSummary()` (the deferred action); the initial render performs zero summary calculations (asserted in `GlobalSaleSummaryAggregateTest`). Query count no longer grows per Sale (43→473 before; 2→2 after). Peak memory differs by test-process baseline (more test classes loaded in the after run), not by this workload; it is not a meaningful comparison here.

### Local MySQL (read-only), one warmup + three samples

Baseline re-run with matched sampling (sum of the three pre-change accessors = the full summary calculation):

| Scope | Before SQL ms (3 samples) | After SQL ms (3 samples) | Queries before → after |
|---|---|---|---|
| default | 4252.7 / 4319.5 / 4302.6 | 2610.7 / 2677.9 / 2634.2 | 1,103 → 3 |
| business+date | 290.4 / 307.0 / 296.2 | 94.3 / 92.7 / 99.6 | 103 → 2 |

Financial results unchanged for both scopes: default outstanding 117 / 615,092,207 (before printed 615,092,206.9999999 — PHP float accumulation; SQL decimal sum is exact), overdue 103 / 377,785,207, recent 3904 / 3,959,918,748; business+date 11 / 29,576,502.83, 9 / 28,869,000, 221 / 565,589,000.

After EXPLAIN (default scope): PRIMARY `sales` type=ALL ≈214,492 rows, left-joined to one-row-per-Sale derived tables (`sale_payments` grouped via sale_payments_sale_status_idx / status index, `spca`, `popt` in/out, `sale_return_adjustments`) with hash joins; dependent per-Sale subqueries are gone. With a narrowing scope each derived aggregate is restricted by a semi-join on scoped Sale ids.

Design note found while measuring: restricting the derived aggregates by a semi-join on the scoped Sale ids made the unfiltered default slower (~3.6 s single run) while making the business+date scope much faster (~95 ms vs ~1.5 s unrestricted). The implementation therefore restricts only when a customer/business/date filter is present.

### What these numbers do and do not show

- SQL/PHP timings only, measured outside the HTTP/Livewire lifecycle; not browser first-paint or complete-operation latency.
- First paint: the summary calculation no longer runs before initial HTML (deferred to `wire:init`), so the initial request saves the full summary time above; the table query still runs before initial HTML (unchanged by design).
- Card selection: no summary recalculation (0 queries) instead of the full summary (~4.3 s default scope on local MySQL before).
- Remaining cost: the default-scope summary still scans all eligible Sales (~2.6 s locally). POS summary/filter projection cost is unchanged (only deferred and given feedback); its full candidate scan remains out of scope.
- Formula/accessor discrepancy (pre-existing, preserved deliberately): membership uses the canonical unclamped live-due predicate, totals sum the per-Sale accessor value (paid clamped at 0 before subtracting). A Sale whose outgoing transfers exceed its payments can therefore be counted with a smaller per-Sale due than the raw predicate implies; both old and new code behave the same.

## 4. Focused test runs (task 5.1)

Command:

```
php artisan test --filter='SaleSummaryCardsTest|GlobalSalePaymentTableTest|GlobalSalePaymentFiltersTest|EmbeddedGlobalSalePaymentIsolationTest|GlobalSalePaymentReturnedPartiallyTest|SaleLiveBalanceProjectionTest|GlobalPosPaymentTableAndSearchTest|PosGlobalPaymentFilterBenchmarkTest|GlobalPaymentCardFilterRestorationTest|GlobalSalesPaymentAuthorizationTest|GlobalPosPaymentAuthorizationTest|GlobalSaleSummaryAggregateTest|GlobalSaleSummaryBenchmarkTest|MainIntegrationOwnershipTest'
```

Result: 173 passed, 1 failed. The failure (`GlobalSalesPaymentAuthorizationTest`, page request 500: `There is no permission named sales.reporting-date.override`) also fails on unmodified `main` (verified with the change stashed) and is unrelated. `SaleDueDateConsumerRegressionTest` has 3 failures that are likewise pre-existing on `main`; it was excluded from the run above.

## 5. Browser verification (task 4.2 smoke / 4.3) — OUTSTANDING

No browser tooling was available in this implementation session. Visible loaders, request ordering, rapid Apply, failed summary retry, failed table refresh and navigation cleanup in standalone and customer-embedded Sales/POS workspaces have NOT been browser-verified. Component/markup assertions are not a substitute; this remains to be done before release.

## 6. Review follow-up: request-failure recovery

Findings addressed (verified against installed Livewire 3.0.5 `dist/livewire.js`: `sendRequestToServer` awaits `fetch()` with no catch, so a rejected fetch skips `handleFailure`, never fires commit `fail` callbacks and leaves `sendingRequest` true, blocking the queue):

- Transport failures: `resources/views/livewire/partials/livewire-transport-guard.blade.php` (included once per page by both workspaces) converts a rejected Livewire update `fetch()` into a synthetic 503 so Livewire's normal failure path runs (commit/request `fail` callbacks fire, queue released) and suppresses the HTML error modal for that synthetic response only.
- HTTP summary failures with no PHP state: `resources/views/livewire/partials/summary-request-failure-state.blade.php` tracks failed `loadSummary`/`retrySummary`/filter-event commits client-side on both summary components, shows a retry (`$wire.retrySummary()`), dims existing totals as not updated, and clears only when a later summary request succeeds.
- Stale failure clearing newer feedback: workspace failure handlers read the card sequence from the failing commit (`toggleCardFilter` param or `sale-filter`/`pos-filter` dispatch param) and release pending state only when it equals the current sequence; commits without a sequence are ignored.

Verification: markup assertions plus `node --check` of the inline scripts only. Behaviour (offline/aborted request, 504 from proxy, rapid card clicks with an older failure) is part of the outstanding browser verification in section 5.
