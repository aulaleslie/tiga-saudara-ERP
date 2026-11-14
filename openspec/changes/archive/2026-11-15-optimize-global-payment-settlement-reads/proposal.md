# Proposal

## Why

After deferred loading and query batching, global POS summaries still hydrate the full candidate graph and global Sales search and pagination repeatedly evaluate expensive relationship and settlement predicates. Read-only local experiments identified substantial query-time and memory reductions while preserving matching IDs, totals and projection values, giving this second phase a measured basis.

## What Changes

- Calculate ordinary POS settlement values through staged database reads; retain the existing resolver/projection for ambiguous mappings, inline checkouts and completed-return cases, and load full ordinary models only for visible rows.
- Preserve the existing inexpensive POS page path when no projection-derived filter is active; evaluate POS search candidates once per read operation without rewriting its search semantics.
- Resolve global Sales search from matching source records, intersect token matches and union complete-input barcode/serial matches, preserving every supported search field and scope.
- Use shared Sales settlement aggregation for summary cards and financial pagination; avoid a second full predicate evaluation for nonempty pages while preserving exact totals and ordering.
- Keep staged reads consistent within each operation and retain the first phase's independent loading, retries, URL state and permissions.
- Verify affected paths with focused equivalence tests, matched MySQL/SQLite evidence and browser smoke checks rather than a full-suite run.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `global-pos-multi-payment`: Memory-efficient financial filtering and summaries with conservative fallback, exact pre-pagination filtering, and consistent scoped reads.
- `global-sales-multi-payment`: Complete candidate-based search, exact efficient financial pagination, and shared fresh summary aggregation.

## Impact

Targets GlobalPosPaymentTable, PosSettlementProjectionService summary reads, GlobalSaleSummaryQuery, SaleTable, and the Sales branch of GlobalPaymentSearchQuery, with focused module read services and tests. Local MySQL is 8.0.46 and the user confirmed production matches; SQLite automated coverage remains required. No framework upgrade, new infrastructure, schema/index migration, persisted financial cache, ledger mutation, or payment-submission change is planned. Normal Sales/Purchase search and the POS search predicate keep their existing behavior.

The proposal uses a staged POS path because a monolithic CTE prototype performed poorly. Experimental timings are planning evidence, not production latency guarantees. Search indexing, a POS search rewrite, effective-date index migrations and financial-semantic corrections are outside this change.
