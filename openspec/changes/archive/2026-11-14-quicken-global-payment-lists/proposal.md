# Proposal

## Why

Global Sales and POS payment lists are slow locally and in production, and their loading feedback does not cover the work users actually wait for. Local read-only investigation found 215,558 eligible Sales, a roughly 2.03-second canonical outstanding-count query, and POS filters that project all candidates before pagination; the earlier 10/50-record benchmark did not establish performance at this scale.

## What Changes

- Defer initial summary calculations on standalone and customer-embedded global Sales/POS workspaces, with separate visible summary and table loading/error states.
- Avoid Sales summary recalculation on card selection and unchanged summary inputs; keep explicit reset, initialization, and changed-input refreshes fresh.
- Calculate Sales outstanding/overdue summary counts and totals in SQL with grouped settlement aggregates instead of hydrating matching Sales and querying balances per record.
- Add committed return-debt reductions to existing Sale read aggregates, with a fresh fallback and write-path cache invalidation.
- Verify only affected financial reads, Livewire event chains, customer isolation, and browser loading behavior; record comparable before/after local measurements.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `global-sales-multi-payment`: Independent summary loading, bounded summary query count, and card selection without unnecessary total recalculation while retaining canonical live balances.
- `global-pos-multi-payment`: Deferred summary initialization and independent loading feedback without changing financial projections or filtered pagination.

## Impact

Targets SaleSummaryCards, PosSummaryCards, SaleTable, their Blade views and global workspace partials, and Sale settlement read aggregates/cache invalidation. Reuses Laravel 10 and installed Livewire 3.0.5; no dependency upgrade, schema migration, index migration, persisted financial cache, or ledger mutation is planned. Existing payment submission remains authoritative.

The larger POS SQL/projection redesign, search optimization, effective-date sort indexing, and unrelated pages are out of scope. POS filtered-query latency may remain high; this first pass improves feedback and eliminates avoidable Sales/summary work without promising a fixed latency.
