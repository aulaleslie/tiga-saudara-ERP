## Why

The current Laporan Laba Rugi subtracts completed purchases from sales, but the business definition is sales minus sales cost minus expenses. Because product average purchase prices can change over time, sales must snapshot historical cost so old profit/loss periods do not drift.

## What Changes

- Add historical cost snapshot fields to sale detail rows so standard sales, POS sales, and imported sales can persist the product average purchase cost used for profit/loss.
- Add a careful backfill/normalization command that replays historical product transactions by effective date and fills sale detail cost snapshots from cumulative tax-exclusive purchase cost up to each sale date.
- Treat product average purchase price as global per `product_id` while continuing to store and read it through `product_prices` for each setting.
- Update future approved purchase/receiving behavior to synchronize the same `average_purchase_price` across all `product_prices` rows for the product.
- Update future live sale/POS write paths to snapshot current average purchase price from the sale's own setting product price row.
- Update Laporan Laba Rugi to report net sales, Beban Pokok Pendapatan from sale cost snapshots, approved expenses, and final Laba (Rugi).
- Preserve selectable company scope, route, permission, and export parity.

## Capabilities

### New Capabilities

- `sales-cost-snapshots`: Defines sale detail cost snapshot storage, historical backfill behavior, global product average price synchronization, future sale snapshot rules, and return cost reversal.

### Modified Capabilities

- `profit-loss-report-setting-scope`: Change the report calculation rows from purchase-based operational cost to sales-cost snapshots while preserving selected setting scope behavior.

## Impact

- Affected schema: `sale_details` gains nullable cost snapshot metadata columns.
- Affected commands: new Artisan dry-run/write/force backfill command for historical sale detail cost snapshots and product price synchronization.
- Affected services/controllers: standard sale, POS sale, sales import, purchase receiving/approval, purchase import normalization, and report services.
- Affected report UI/export: Laporan Laba Rugi rows and totals switch from `Pembelian`/`Retur Pembelian` cost to `Beban Pokok Pendapatan` based on sale cost snapshots.
- Affected tests: migration, backfill, purchase average synchronization, sale snapshot persistence, sale return cost reversal, profit/loss screen/export parity, and focused edge cases for tax, bundles, non-stock products, fallback warnings, and idempotency.
