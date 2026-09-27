## Why

Purchase import currently only treats `cv tiga nusa` and `cv top it` as owner-routing tags. Other historical source tags such as `aries`, `rahmat`, `agus`, and `perdana` fall back to PERDANA, even though Sales import still carries the full tag-to-owner mapping.

This creates inconsistent ownership semantics between purchase and sales imports and prevents purchase imports from using known source-system owner tags that operators expect to be meaningful.

## What Changes

- Restore the full purchase import tag owner mapping:
  - `cv tiga nusa` -> `CV TIGA NUSA COMPUTER`
  - `cv top it` -> `CV TOP IT INTERNUSA`
  - `aries` -> `TIGA COMPUTER`
  - `rahmat` -> `WHITE KNIGHT COMPUTER`
  - `agus` -> `DUNIA COMPUTER`
  - `perdana` -> `PERDANA`
- Keep Daizu product detection higher priority than mapped tags.
- Keep blank and unmapped purchase tags falling back to PERDANA.
- Treat the restored mapping as owner-routing behavior for grouping, duplicate checks, document owner, price owner, stock owner, location owner, and inventory transactions.
- Preserve raw CSV tag syncing as metadata where existing import behavior supports it.

## Capabilities

### Modified Capabilities

- `purchase-import-daizu-ownership`: Restore the full purchase import mapped-tag owner table while retaining Daizu-first and PERDANA fallback behavior.

## Impact

- Affected code: `Modules/Purchase/Services/PurchaseImportService.php` and focused purchase import ownership/payment tests.
- Affected behavior: future purchase imports with tags `aries`, `rahmat`, or `agus` will route to their mapped owners instead of PERDANA.
- Affected tests: tests that currently assert `aries`, `rahmat`, or `agus` route to PERDANA must be revised to match restored tag ownership.
- No database migration or historical data backfill is planned.
