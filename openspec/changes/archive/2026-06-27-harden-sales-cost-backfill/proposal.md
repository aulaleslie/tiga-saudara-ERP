## Why

The `sales:backfill-cost-snapshots --write --force` command can calculate invalid historical unit costs when negative-stock replay leaves a poisoned running value, causing MySQL numeric overflow and partial write runs. The same command is also expensive on production-sized data because it reconstructs timelines product-by-product with Eloquent-heavy queries.

## What Changes

- Harden historical moving-average replay so negative stock cannot carry negative inventory value into later purchase averages.
- Correct backfill purchase cost calculation to use tax-exclusive DPP after line discount.
- Add explicit guardrails for negative, non-finite, and unrealistic unit costs, with suspicious rows reported instead of crashing write mode.
- Preserve dry-run, write, force, product, setting, start, and end options while ensuring date-filtered runs still replay earlier events required for opening inventory state.
- Improve command audit output so negative-stock and suspicious-cost warnings include enough product/detail/date context for cleanup.
- Improve backfill performance by reducing per-product query amplification, unnecessary eager loading, and row-by-row update overhead.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `sales-cost-snapshots`: Historical backfill requirements are tightened for safe moving-average replay, DPP discount handling, outlier guardrails, filtered replay correctness, audit warning detail, and production-scale performance.

## Impact

- Affected command: `Modules/Sale/Console/BackfillSalesCostSnapshotsCommand.php`.
- Affected data reads: products, purchases, purchase details, received notes/details, purchase returns/details, sales, and sale details.
- Affected persisted data: `sale_details.cost_unit_snapshot`, `sale_details.cost_total_snapshot`, `sale_details.cost_snapshot_source`, and `sale_details.cost_snapshot_at`.
- Tests should cover the existing `Modules/Sale/Tests/Feature/BackfillSalesCostSnapshotsCommandTest.php` scenarios plus negative-stock recovery, suspicious-cost skipping/reporting, discounted purchase DPP, and date-filtered replay state.
- Optional schema impact: additive indexes may be added to support efficient event replay; no destructive data migration is intended.
