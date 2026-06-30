## 1. Test Coverage

- [x] 1.1 Add a focused backfill test proving `CV TIGA NUSA COMPUTER` sale snapshots use only Tiga Nusa purchase history when that bucket has eligible purchases.
- [x] 1.2 Add a focused backfill test proving `CV TOP IT INTERNUSA` sale snapshots use only Top IT purchase history when that bucket has eligible purchases.
- [x] 1.3 Add a focused backfill test proving non-special setting sale snapshots use REST/global purchases and exclude Tiga Nusa and Top IT purchases.
- [x] 1.4 Add coverage proving purchase returns consume only within their classified bucket and do not affect other bucket averages.
- [x] 1.5 Add coverage proving a special-company sale falls back to REST/global purchase history when its own bucket has no eligible purchase history.
- [x] 1.6 Add coverage proving `--setting` for a special setting writes only that setting and replays the matching special bucket.
- [x] 1.7 Add coverage proving `--setting` for a non-special setting writes only that setting while using REST/global bucket context.
- [x] 1.8 Add regression coverage proving bucket-aware backfill keeps existing `BACKFILL_*` and `NON_STOCK_ZERO` source labels.

## 2. Bucket Classification

- [x] 2.1 Add setting bucket resolution for `CV TIGA NUSA COMPUTER`, `CV TOP IT INTERNUSA`, and REST/global using trimmed case-insensitive company names.
- [x] 2.2 Attach bucket metadata to purchase events from parent purchase setting.
- [x] 2.3 Attach bucket metadata to purchase return events from parent purchase return setting.
- [x] 2.4 Attach bucket metadata to sale events from parent sale setting.

## 3. Replay Behavior

- [x] 3.1 Refactor product replay so running quantity, running value, current average, negative-stock detection, and sale consumption are tracked independently per bucket.
- [x] 3.2 Preserve existing DPP purchase event construction, approved receipt ordering, missing receipt warning behavior, and deterministic same-date ordering inside each bucket.
- [x] 3.3 Implement REST/global purchase fallback for special buckets with no eligible special purchase history.
- [x] 3.4 Preserve future-purchase fallback and zero fallback semantics using the sale's bucket or applicable REST/global fallback source.
- [x] 3.5 Preserve exact `--product`, `--setting`, `--start`, `--end`, `--write`, and `--force` semantics while applying bucket-aware replay.

## 4. Write Path and Reporting

- [x] 4.1 Keep batch updates writing only existing sale detail cost snapshot columns with unchanged source labels.
- [x] 4.2 Keep summary counters and suspicious-cost warnings accurate under bucketed replay.
- [x] 4.3 Ensure dry-run mode reports planned bucket-aware work without database writes.

## 5. Verification

- [x] 5.1 Run the focused backfill command test class.
- [x] 5.2 Run focused sales cost snapshot tests that cover live sale/POS snapshot behavior to confirm runtime behavior remains unchanged.
- [x] 5.3 Run `openspec status --change bucket-sales-cost-backfill` and confirm the change is apply-ready.
