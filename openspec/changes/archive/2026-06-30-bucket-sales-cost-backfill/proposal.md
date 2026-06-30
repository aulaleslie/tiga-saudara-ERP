## Why

Historical sales cost backfill currently replays one global product moving-average timeline, so sales for `CV TIGA NUSA COMPUTER` and `CV TOP IT INTERNUSA` can inherit cost from purchases made by other companies. Recent purchase-price normalization already isolates those two companies for historical repair, and sales cost snapshots need the same historical bucket boundary so profit/loss HPP is normalized consistently.

## What Changes

- Make `sales:backfill-cost-snapshots` bucket-aware for historical replay only:
  - `CV TIGA NUSA COMPUTER` uses its own purchase, purchase return, and sale timeline.
  - `CV TOP IT INTERNUSA` uses its own purchase, purchase return, and sale timeline.
  - All other settings share the REST/global timeline.
- Use REST/global purchase history as the fallback for either special company when that special bucket has no eligible purchase history for the product.
- Keep existing purchase DPP cost basis, effective-date ordering, force/non-force behavior, date filters, warning categories, suspicious-cost guardrails, and batch writes.
- Keep `--setting=<id>` as an exact write filter:
  - A special setting replays and writes only that special bucket.
  - A non-special setting writes only that setting's sales while using REST/global bucket context.
- Keep existing `BACKFILL_*` snapshot source labels unchanged.
- Preserve future/runtime purchase approval and live sale snapshot behavior as global/current behavior; this change is only for historical backfill.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `sales-cost-snapshots`: Historical backfill replay now uses special-company buckets for `CV TIGA NUSA COMPUTER` and `CV TOP IT INTERNUSA`, REST/global for other settings, and REST/global fallback for empty special purchase history.

## Impact

- Affected command: `Modules/Sale/Console/BackfillSalesCostSnapshotsCommand.php`.
- Affected support logic: reusable bucket classification may be added near the sales backfill command or as a small shared support class.
- Affected tests: `Modules/Sale/Tests/Feature/BackfillSalesCostSnapshotsCommandTest.php` needs focused multi-setting coverage for isolated buckets, REST fallback, purchase returns, `--setting`, and unchanged source labels.
- Affected data: only `sale_details.cost_unit_snapshot`, `sale_details.cost_total_snapshot`, `sale_details.cost_snapshot_source`, and `sale_details.cost_snapshot_at` rows written by `sales:backfill-cost-snapshots --write`.
- Not affected: live purchase approval global average synchronization, live standard/POS sale snapshot capture, product purchase price normalization, product sales prices, tier prices, and schema.
