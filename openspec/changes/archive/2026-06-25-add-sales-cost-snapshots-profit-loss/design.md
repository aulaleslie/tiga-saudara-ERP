## Context

`/profit-loss-report` currently calculates Laporan Laba Rugi as completed sales minus sale returns, then subtracts completed purchases minus purchase returns plus approved expenses. That behavior matched the prior operational-report decision, but it does not match the corrected business definition: laba rugi should use sales revenue, product sales cost, and expenses.

The application already stores product average purchase price in `product_prices` per product and setting, but that value is mutable. If the report joins current product prices directly, old profit/loss periods will drift whenever purchase costs change. The durable boundary needs to be a sale-detail-level cost snapshot captured when a sale is posted and backfilled for historical sales.

Historical imports are expected to load purchases first and sales later, but the backfill command must still calculate by effective transaction dates rather than import order. Product IDs are global in current behavior; legacy setting-specific product columns should not drive the cost calculation.

## Goals / Non-Goals

**Goals:**

- Persist historical product cost snapshots on sale detail rows.
- Backfill existing sale details by replaying product purchase and sale history by effective date.
- Calculate purchase average cost from tax-exclusive DPP after line discount.
- Treat `average_purchase_price` as global per `product_id` while continuing to read and write through `product_prices` rows for each setting.
- Synchronize future purchase average updates to all settings for the product.
- Snapshot current average purchase price for future live standard sales and POS sales.
- Allow historical sales imports to be normalized by running the backfill command after import.
- Update Laporan Laba Rugi to use net sales, Beban Pokok Pendapatan from sale cost snapshots, expenses, and final Laba (Rugi).
- Reverse sale return cost from the original sale detail snapshot.
- Provide dry-run, write, and force modes with detailed warnings and idempotent behavior.

**Non-Goals:**

- No full accounting ledger or chart-of-account implementation.
- No FIFO/LIFO inventory costing.
- No per-setting divergent average purchase price.
- No costing for non-stock-managed products; they are explicitly zero-cost for this report.
- No rewrite of product identity; duplicate legacy product-code issues are reported but not resolved by this change.
- No automatic historical-average lookup inside sales import; imports may rely on the backfill command after import.

## Decisions

### D1: Store sale cost snapshots on `sale_details`

Add nullable snapshot columns to `sale_details`:

- `cost_unit_snapshot decimal(15,6)`
- `cost_total_snapshot decimal(15,2)`
- `cost_snapshot_source string`
- `cost_snapshot_at timestamp`

Rationale: the report needs stable historical sales cost. Persisting the cost on each sale detail avoids joining mutable product price values and makes returns easy to reverse.

Alternative considered: calculate cost in the report from current `product_prices.average_purchase_price`. Rejected because historical reports would drift.

### D2: Keep `product_prices` as the average-price read/write surface

The system will continue reading average purchase price from the sale's own setting row in `product_prices`, but all `product_prices.average_purchase_price` values for the same `product_id` must be synchronized to the same value.

Rationale: existing sale/POS code already works in a setting context. Keeping this surface minimizes changes while enforcing one global cost basis.

Alternative considered: add a new global product-cost table. Rejected because it introduces another source of truth.

### D3: Backfill replays effective dates, not import order

The backfill command builds a global product timeline from eligible purchase and sale events. For each product, purchase cost events use purchase date or approved receiving date when available; sale events use sale date. Sale snapshots use the latest cumulative average from purchase events with effective date less than or equal to the sale date.

If a sale has no prior purchase, the command uses the earliest future purchase for that product as a fallback. If no purchase exists, stock-managed products receive zero cost with a warning. Non-stock-managed products always receive zero cost with a source marker.

Rationale: users may import all purchases first and sales later, so database creation/import order cannot represent historical cost sequence.

Alternative considered: use current normalized average purchase price for every historical sale. Rejected because it applies future purchases to past sales.

### D4: Use tax-exclusive DPP after line discount as purchase cost

Backfill and future average calculations must derive purchase unit cost from line DPP after discount:

```text
line_dpp = sub_total - product_tax_amount
line_cost = line_dpp - product_discount_amount
unit_cost = line_cost / quantity
```

Rationale: PPN/input tax is tracked separately and should not inflate inventory/product cost. This also avoids relying on `unit_price`, which may be tax-included in some purchase/import flows.

Alternative considered: use `purchase_details.price` or `unit_price`. Rejected because those fields are not consistently tax-exclusive across flows.

### D5: Purchase returns reduce historical average and stock value

Purchase return events should reduce stock quantity and stock value. Where a direct original purchase cost can be resolved, use that cost; otherwise remove value at the current running average at the return date.

Rationale: purchase returns affect the quantity and value available for later sales. Ignoring them can overstate later sales cost.

Alternative considered: ignore purchase returns during backfill. Rejected because it makes the moving average too high after returned purchases.

### D6: Sale returns reverse original sale detail cost snapshots

Sale return cost must be calculated from `sale_return_details.sale_detail_id` and the original sale detail `cost_unit_snapshot`, multiplied by returned quantity. The return date controls which profit/loss period receives the reversal.

Rationale: returns should undo the cost originally recognized, not recalculate cost from the return date average.

Alternative considered: use current product average on return date. Rejected because it mismatches the original sale cost.

### D7: Live sales snapshot current average immediately; historical imports normalize later

Standard live sales and POS sales should snapshot from `product_prices.average_purchase_price` for the sale setting during posting/finalization. Sales imports may create historical sale details without accurate snapshots, provided users can run the backfill command after import.

Rationale: live paths should be immediately reportable, while historical imports need effective-date replay across many rows.

Alternative considered: perform full historical replay during every import row. Rejected because it is too expensive and fragile for bulk imports.

### D8: Backfill is safe by default

The command defaults to dry-run. `--write` fills null snapshots only. `--force` recomputes existing snapshots. Optional filters can constrain product, setting, and date ranges. The command reports counts and warning categories before writes.

Rationale: this touches financial-report history and must be auditable before applying.

Alternative considered: single write-only repair command. Rejected due to high operational risk.

## Risks / Trade-offs

- [Risk] Missing early purchase history can understate historical cost. -> Mitigation: use earliest future purchase fallback and report each fallback in dry-run/write summaries.
- [Risk] Negative stock during replay can make moving average less reliable. -> Mitigation: continue with defined cost rules but report product/date/reference warnings.
- [Risk] Legacy duplicate products with the same code/barcode may split purchase history. -> Mitigation: dry-run reports duplicate product identities; this change does not merge products.
- [Risk] Rounding too early can create report drift. -> Mitigation: store unit snapshots with six decimals and round total snapshots to two decimals only at the row total.
- [Risk] Future code paths may forget to sync global average prices. -> Mitigation: centralize average synchronization in a service and cover purchase receiving/import paths with tests.
- [Risk] Non-stock-managed service lines may appear as revenue with zero cost. -> Mitigation: this is intended behavior and the snapshot source identifies zero-cost non-stock rows.
- [Risk] Backfill may be expensive on production-size data. -> Mitigation: chunk by product, preload related purchase/sale details, and support filters.

## Migration Plan

1. Add nullable cost snapshot columns to `sale_details` with indexes only where needed for backfill/report filtering.
2. Add a shared sales-cost snapshot/backfill service that can calculate purchase DPP cost, maintain running average, and produce warnings.
3. Add the Artisan command in dry-run mode first; verify summary output in staging.
4. Update purchase average synchronization to write identical `average_purchase_price` values to all `product_prices` rows for each product.
5. Update live standard sale and POS sale write paths to snapshot current average cost for stock-managed products.
6. Update Laporan Laba Rugi service, UI, and export to use cost snapshots and return-cost reversals.
7. Run the backfill dry-run in staging, resolve severe warnings, then run `--write`.
8. Re-run the command to confirm idempotency.

Rollback strategy: the migration is additive. If report behavior must be reverted, leave snapshot columns in place and restore the previous report service formula. Backfilled snapshot data can remain unused.

## Open Questions

- Exact Artisan command name and output format can be chosen during implementation.
- Whether purchase return original-cost matching can always be resolved from existing links should be verified while implementing the replay service.
