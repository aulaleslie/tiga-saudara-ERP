# Design

## Context

See proposal.md for motivation. `GlobalPosPaymentTable::render()` eagerly loads all candidates when a projection-derived filter is active, projects them, constrains a new paginated query to matching IDs, and projects the visible rows again. `PosSummaryCards` independently calculates totals on render and receives an event after every Apply Filter operation.

`PosSettlementProjectionService::reachableSalesEagerLoad()` batches original Sale relationships, but `PosReachableSalesResolver::resolveReplacementSales()` still queries returns per checkout, followed by return lines, dispatch details, and replacement Sales where present. Both split and inline mappings call that path. Existing specs in `openspec/specs/global-pos-multi-payment/spec.md` remain authoritative and unchanged.

## Goals / Non-Goals

**Goals:** Reduce database round trips and duplicate settlement calculations while preserving resolver diagnostics, financial freshness, summary scope, and exact pagination semantics. Obtain comparative evidence without imposing an unsupported latency target.

**Non-Goals:** SQL settlement aggregation, removing all full-candidate scans, persistent caches, migrations, indexes, payment mutation changes, and a full test-suite run.

## Decisions

### 1. Batch replacement discovery with operation-local context

Add a collection preparation path shared by list and summary projection callers. Fetch completed, non-reversed returns for all candidate checkout IDs or transaction IDs, replacement lines, dispatch links, and replacement Sales with the existing archive bypass and settlement eager loads. Associate results back to each checkout using the same checkout-ID OR transaction-ID rule. Represent known-empty results explicitly so a batch with no returns performs no per-checkout fallback queries.

Keep the existing single-record resolver path for callers that do not provide prepared context. Preserve split-versus-inline precedence, mapping order, existing Sale exclusion, duplicate handling, customer diagnostics, missing-Sale diagnostics, and return scopes. Do not store prepared context in a singleton or retain it across operations; payment preview/submission must still read fresh state.

Alternative: simply eager-load return relations per record. Rejected as the primary approach because replacement discovery follows both checkout and transaction identity and requires preserving missing-link diagnostics across dispatch links.

### 2. Reuse projections within the table render

Calculate a projection map keyed by transaction ID while evaluating projection-derived filters, and attach those calculated values to fetched page rows instead of projecting twice. Prepare and project just the visible page when no projection-derived filter is active. Keep filtering before pagination, current totals, stable secondary ordering, search intersection, and query-based pagination.

Load presentation-only relations for visible rows where feasible; do not hydrate cashier, terminal, creator, customer, or setting solely for a settlement scan. Retain all keys and settlement relationships required by the resolver.

Alternative: paginate first and filter the page. Rejected because it produces incorrect counts and incomplete pages. A SQL rewrite is deferred.

### 3. Separate card selection from summary computation

Card selection alone does not alter summary inputs. Update card highlighting locally and dispatch the existing table-filter event without rerendering/recalculating summary totals solely for selection. Keep URL selection synchronization, deselection, and loading/completion/failure behavior intact. Use the existing Livewire/Alpine conventions rather than persisting financial totals in browser state.

For Apply Filter, distinguish changes to summary inputs (business, customer, transaction dates, due dates) from table-only changes (payment status, cashier, terminal, search). Avoid a sibling summary refresh when only table inputs change. Preserve current summary scope rather than making the cards follow additional filters. A refresh with changed summary inputs, reset, navigation, or full workspace reload must calculate fresh totals. Do not introduce cross-request financial result caching. Do not suppress reset refreshes, including resetting to an already-default state.

Alternative: cache summary totals with a time-to-live. Rejected because invalidation would require tracking payments, credits, monetary edits, returns, and date boundaries and could expose stale balances. Reusing work across separate component requests is also deferred.

### 4. Focused correctness and performance verification

Capture before/after measurements against the same isolated fixture, frozen date, database driver, filters, and page size. Cover a small set and a materially larger set (for example 10 and 100 eligible transactions), including ordinary records without returns and a mixed split/replacement fixture. Record SQL query count and time, elapsed operation time, projection count, and peak memory with environment details. Separate table and summary measurements; include a browser or staging smoke check for the complete event chain when available. Do not claim production latency from SQLite timings.

The key deterministic performance checks are that replacement-discovery query count does not grow per transaction within a batch, visible rows are not projected twice, and card selection/table-only filters do not trigger unnecessary summary calculation. Timing comparisons are evidence, not brittle test thresholds. Repeated runs must disclose warmup and sample counts.

Extend affected tests only: resolver/projection equivalence for inline, split, replacement and invalid mappings; financial edge cases already exercised by these classes; pre-pagination filters and tied ordering; summary events and loading feedback. Run the affected POS classes or focused methods with `php artisan test`. No full-suite or `composer test:fresh-sqlite` task is required.

## Risks / Trade-offs

- [Full-candidate work remains proportional to matching records] → Report residual elapsed time and memory; defer SQL aggregation until evidence warrants it.
- [Batch association could change return mapping or diagnostics] → Compare batched and single-record projections, including checkout/transaction identity matches, shared replacement IDs, missing Sales, and archived records.
- [Avoiding summary renders can disrupt highlighting or loading overlays] → Verify card select/deselect, filter reset, URL state, completion, and error handling in focused component tests and browser smoke checks where available.
- [Long-lived workers or reused services could leak context] → Use explicit operation-local data and exercise consecutive independent projection operations.
- [Table-only interactions no longer incidentally refresh card totals] → Preserve deliberate summary-input/reset/navigation refreshes and keep financial data uncached; payment actions retain fresh authoritative validation.

## Migration Plan

Deploy application code through the existing release process with no database or infrastructure steps. Roll back by reverting the application refactor; no data conversion or cleanup is needed.
