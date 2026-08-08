# Repair Missing Sales Cost Snapshots

## Why

Production sale details carry `cost_unit_snapshot = 0` for an unknown number of rows, which makes every affected line report 100% margin in profit/loss, inventory valuation, and general ledger reports. There is currently no way to measure the size of this gap, and the existing `sales:backfill-cost-snapshots` command cannot close it: rows that landed at zero were stamped `BACKFILL_ZERO_FALLBACK`, and the command's skip predicate treats any `BACKFILL_*` source as already-handled, so re-running leaves them at zero permanently while `--force` would rewrite good snapshots alongside them.

The data is already on production with no cleanup window, so the work must be measurable before it is corrective, and corrective writes must be confined to rows that are already zero.

## What Changes

- **New read-only audit command** `sales:audit-cost-snapshots` that reports how many sale details lack a usable cost snapshot, defining "missing" by the observable fact `cost_unit_snapshot <= 0 OR IS NULL` rather than by `cost_snapshot_source`. Source labels are reported as a diagnostic dimension only, never as a filter, because `BACKFILL_ZERO_FALLBACK` and `MISSING_AVERAGE_PRICE` both claim a row was handled while leaving it at zero.
- **Audit partitions the gap** by whether the product has any eligible purchase history, which separates repairable rows from rows no purchase-derived cost can ever resolve.
- **Audit breakdowns** by setting, by sale year-month, by current source label, by top-N affected products, and a distance histogram showing how far each repairable row sits from its nearest eligible purchase.
- **New repair path** that fills only rows whose cost is currently zero, anchored on the nearest eligible purchase for the same product — preferring the nearest purchase at or before the sale date, falling back to the nearest purchase after the sale date, then to a cross-bucket purchase, and otherwise leaving the row at zero.
- **Terminal labeling for unrepairable rows**: products with no eligible purchase history keep cost zero but receive a distinct source label so they are retired from subsequent audits instead of reappearing as unfinished work.
- **Repair records its evidence**: the anchor purchase detail and the signed distance in days between purchase and sale, with a `--max-distance-days` guard that fails closed to zero rather than writing a stale cost.
- **Repair never overwrites a positive cost snapshot**, under any flag. There is no force mode on this path.
- Existing `sales:backfill-cost-snapshots` behavior is left unchanged by this proposal; the known skip-predicate and per-product fallback-date defects are recorded in design as explicitly deferred.

## Capabilities

### New Capabilities
- `sales-cost-snapshot-audit`: Read-only measurement of sale details lacking a usable cost snapshot, using cost value rather than source label as the definition, partitioned by repairability and reported across setting, period, product, and purchase-distance dimensions.

### Modified Capabilities
- `sales-cost-snapshots`: Adds a nearest-purchase repair path restricted to sale details whose cost snapshot is zero or null, with new source labels for anchored repairs and for products with no purchase history. Also corrects the existing requirement that describes preserve-by-default behavior in terms of null snapshots, which does not match the shipped source-prefix implementation.

## Impact

- **New**: `Modules/Sale/Console/AuditCostSnapshotsCommand.php`, repair command or repair mode, supporting service for nearest-purchase anchor resolution.
- **Modified**: `Modules/Purchase/Services/HistoricalReplayEngine.php` — the receipt-aware landed-cost calculation currently embedded in `buildFallbackAverages` must be extracted so a single `PurchaseDetail` can be priced without the forward-only scan wrapped around it.
- **Reads**: `sale_details`, `sales`, `purchase_details`, `purchases`, `received_notes`, `products`.
- **Writes**: `sale_details.cost_unit_snapshot`, `cost_total_snapshot`, `cost_snapshot_source`, `cost_snapshot_at` — only where `cost_unit_snapshot <= 0 OR IS NULL`.
- **Downstream**: profit/loss, inventory valuation, and general ledger report services consume these snapshots; filling zeros will change reported margins for affected lines. No currently non-zero value changes.
- **Open**: whether any products have `stock_managed = false` in this dataset could not be confirmed (no database access during authoring), so the audit treats it as a reported dimension rather than an assumption. Bundle parent sale details carry the cost snapshot while `sale_bundle_items` has no cost columns, so bundle parent products are expected to appear in the no-purchase-history partition.
