# Sale failure baseline comparison

Command used in the current tree and in an isolated clean worktree:

```bash
composer test:fresh-sqlite -- --filter='GlobalSaleAdjustmentTest|GlobalSaleDetailInlineEditorTest'
```

| Source | Commit | Result |
|---|---|---|
| Current uncommitted implementation | 6573f9d9 + working tree | 11 failed, 20 passed, 51 assertions |
| Clean HEAD | 6573f9d9 | 11 failed, 20 passed, 51 assertions |
| Clean feat/sales-return | 409d693a | 11 failed, 20 passed, 51 assertions |

All 11 failures match by test source location and cause. Nine GlobalSaleDetailInlineEditorTest failures lack sales.due-date.override permission. GlobalSaleAdjustmentTest fails at line 153 because Cetak Faktur is present, and at line 342 because tax is 99.1 instead of 110.0. These failures predate the replacement-accounting implementation. This comparison does not assert that the entire Sale suite is green or that errors masked by missing permissions cannot exist.

The clean worktree had its own dependency copy, SQLite database, compiled views, and matching local Vite asset setup. No stash or original source edits were used. Full outputs are current.log, clean-head.log, and clean-feature.log.
