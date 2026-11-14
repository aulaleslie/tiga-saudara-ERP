# Design

## Context

See proposal.md for motivation and scope. SaleSummaryCards currently hydrates outstanding and overdue Sales and sums live_due_amount; their accessors can issue four payment/transfer queries plus a return-adjustment query per Sale. SaleTable already uses withLiveSettlementAggregates but that scope lacks debt reductions. getCommittedDebtReduction always queries; discardSettlementReadCaches currently clears only the four existing aggregate shortcuts.

POS already batches replacement discovery and retains summaries across selection, but PosSummaryCards calculates in mount and has a render fallback. Both workspace partials render summary components synchronously. Sales has no summary/table loading UI; the POS summary loader targets toggleCardFilter only. The installed dependency is Livewire 3.0.5, so modern isolation attributes are not assumed available. Existing Sales summary/table financial semantics and POS recent-payment behavior are not identical; do not unify them in this change.

Local investigation used read-only PDO, not the application request lifecycle: 215,558 eligible Sales, 117 positive-due Sales, 11,256 completed POS transactions; one outstanding-count query took 2,029 ms. EXPLAIN showed a full Sales scan and dependent settlement subqueries. These are single-run evidence, not release thresholds or production timings.

## Goals / Non-Goals

**Goals:** Remove per-Sale summary balance queries and model hydration, show initial summary placeholders before expensive work, preserve read/write financial correctness, and make actual request phases visible.

**Non-Goals:** No POS SQL projection rewrite, changes to summary meanings, altered date/search scope, framework upgrade, persisted totals cache, migration/index project, or fixed-latency promise. Initial table queries still run before initial HTML is delivered. Summary work may still scan substantial data.

## Decisions

### 1. Explicit deferred summary initialization

Keep cheap authorized mount/context setup, introduce explicit summary readiness/loading/error state, and trigger a summary-load action with wire:init supported by the installed version. Guard templates, computed accessors, and POS render fallback so initial render never calculates summaries. The table remains server-rendered; only summaries are deferred. This avoids requiring request isolation or changing every list into an asynchronous shell. Use retry to invoke the same authorized calculation with current inputs. Recheck global access on deferred/retry actions and keep customer/global mode locked.

Filter updates arriving before initialization must update inputs without triggering duplicate work or losing the latest state. Serialize summary updates or retain a monotonic applied-input revision and reconcile stale completions; only matching current results may replace displayed totals. Do not mark completion merely because a fixed timer expired.

Alternative: lazy-mount both components. Deferred because it changes table initialization and authorization timing, and request pooling in this old version must not be assumed to isolate concurrent work.

### 2. Group settlement data once for Sales summary reads

Use a focused read query/service in the Sale module. Independently aggregate active monetary payments, active credit applications, effective incoming/outgoing owner transfers, and committed return-debt reductions by Sale ID, then left-join those one-row-per-Sale datasets to scoped eligible Sales. Never directly join several one-to-many ledgers before aggregation. Restrict aggregates to relevant eligible scope where practical and supported by MySQL/SQLite.

Retain existing eligibility/live-due predicates and match per-Sale effective-paid rounding/clamping and per-Sale due rounding/clamping for totals; do not replace totals with a clamp over the grand sum. Preserve archive/customer/business/document-date/due-date scope and Carbon application-day boundaries. Compute outstanding and overdue via conditional SQL count/sum without loading Sale models. Keep recent collections as active payment amounts over currently eligible fully paid Sales with distinct Sale counts and existing inclusive 30-day bounds; it can reuse grouped balances but must not become a sum of invoice totals. Compare before/after outputs including transfers with invalidated or absent original payments and overpayments. Any pre-existing formula/accessor disagreement must be identified explicitly rather than silently broadening eligibility.

Alternative: add eager-loaded sums but retain collection->sum. Smaller code change, but still hydrates all matched models and leaves correlated balance filtering as the main large-data cost. Grouped SQL is preferred for the agreed first-pass query improvement.

### 3. Complete the existing table read aggregates safely

Extend withLiveSettlementAggregates with a committed-debt-reduction sum. Make getCommittedDebtReduction use the explicit aggregate when present, including a known zero; otherwise query fresh as today. Add that shortcut to discardSettlementReadCaches and verify reconciliation clears it before authoritative reads. Do not change cash-refund/net-total accessors or POS projection arithmetic as collateral work. Shared scope changes must preserve ordinary setting-scoped Sale table behavior.

### 4. Reuse summaries within one mounted workspace

Give global Sales summaries explicit calculated totals and normalized input identity like POS, including immutable customer ID and sorted business IDs. Card selection updates highlight and dispatches its table filter without recomputing totals. Changed summary inputs, initial load, retry, navigation/remount, and explicit Reset calculate fresh data; unchanged Apply and table-only actions do not. Reset refreshes even when already default. This is mounted-component state, not shared TTL caching. Non-global SaleSummaryCards retains its existing semantics.

### 5. Track UI operations across event chains

Use component-local loading UI for direct requests and scoped workspace pending state for the click-to-listener gap. Add Sales table/summary feedback and include POS summary load/filter/retry actions. Start pending feedback when an applied operation begins; finish the relevant phase only after successful DOM update or a handled failure. Track summary and table work separately, scope events by workspace identity, prevent overlapping duplicate actions or correlate them, and clean up hooks on navigation/destruction. Do not use global failure handlers that clear unrelated requests. An unsuccessful refresh leaves prior totals explicitly stale or replaces them with an error; never silently relabel them current.

The existing table-to-summary event may remain sequential after Apply. This change makes that sequence visible; it does not claim concurrent completion. Test rapid applies and card double-clicks so older completions cannot remove newer pending state.

### 6. Focused verification and comparable evidence

Extend affected tests only: SaleSummaryCardsTest, GlobalSalePaymentTableTest/FiltersTest, EmbeddedGlobalSalePaymentIsolationTest, GlobalPosPaymentTableAndSearchTest and matching existing embedded POS coverage. Add aggregate equivalence cases for unpaid/partial/paid, active/invalidated payments, credits, incoming/outgoing transfers, multiple records per ledger, committed reductions, rounding/clamping, date boundaries, archive exclusions, and customers/businesses. Verify the aggregate and fallback agree and a preloaded reduction is discarded before reconciliation.

Measure query counts on two fixture sizes (10 and 100 eligible Sales); no per-Sale balance queries in the summary or table reduction read, and no summary calculation on selection/unchanged Apply. Initial HTML must perform zero summary calculations. Use targeted PHPUnit methods/classes with php artisan test; no full-suite or fresh full-database task.

Browser smoke verification is required for visible loaders, since component assertions cannot establish animation/paint or request pooling: check standalone Sales/POS and embedded workspaces, slow summary initialization, Apply/Reset, search/page/sort, card toggle, rapid Apply, failed summary retry, failed table request, and navigation cleanup. Record actual request timing/ordering. If browser tooling is unavailable, report that verification as outstanding rather than treating template checks as a pass.

Before implementation, capture a matched baseline; after, repeat the same workload, scope, page size, frozen date, driver, and measurement boundaries. Use one warmup and three samples, record SQL count/time, elapsed time and peak memory separately for table, summary, and full event chain. For large local MySQL, use read-only measurements of default and business/date scopes and EXPLAIN; do not seed or mutate that database. Keep test fixtures isolated in SQLite. Store evidence in benchmark.md under this change during implementation, disclose dataset/driver differences, and report residual POS scan cost. A faster placeholder alone is not evidence of a faster query.

## Risks / Trade-offs

- [Grouped joins multiply amounts or alter clamps] -> Aggregate each ledger independently and compare financial edge cases against authoritative reads.
- [New aggregate becomes stale on financial writes] -> Extend cache invalidation and test mutation/reconciliation after a preloaded read.
- [Deferred events race or expose unscoped data] -> Locked context, action authorization, latest-input reconciliation and scoped events.
- [Summary UI feels fast while SQL remains expensive] -> Separate first-paint, query and complete-operation measurements; report remaining scans.
- [Global changes affect normal Sales components] -> Gate summary initialization/caching behavior to global workspaces and run one focused non-global regression.

## Migration Plan

Deploy application changes only after focused checks and browser evidence; no data migration or financial backfill. Roll back by reverting application code. No shared cache invalidation or data repair is needed.
