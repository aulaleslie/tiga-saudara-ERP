## Why

Sales and purchase imports currently create business documents without stock mutation, while the stock snapshot import hardens `product_stocks` and creates `ADJ` transaction rows from runtime stock values. During ERP initialization this leaves the transaction list without a coherent historical `BUY`/`SELL` ledger, and stock snapshot adjustments can appear as zero or misleading deltas instead of reconciling imported purchase/sales history to the source stock snapshot.

This change introduces an initialization-only transaction normalization workflow so imported sales and purchases remain fast and stock-neutral, while transaction history is rebuilt deterministically before the product stock snapshot import hardens current stock.

## What Changes

- Add an initialization-only console command that truncates `transactions` and rebuilds normalized `BUY` and `SELL` transaction rows from imported purchase and sale documents.
- The command calculates historical `previous_quantity`, `after_quantity`, `previous_quantity_at_location`, and `after_quantity_at_location` per product, setting, and setting location using purchase/sale dates and deterministic tie-breakers.
- Keep sales and purchase import runtime paths stock-neutral: they must not update `product_stocks` and must not create transaction rows during import processing.
- Update stock snapshot import so its `ADJ` transaction uses the latest normalized transaction ledger balance as the previous quantity, then hardens `product_stocks` to the snapshot source quantity.
- Preserve stock snapshot import as the only import path that writes `product_stocks`.
- Mark the normalization command as destructive initialization tooling, requiring explicit flags before truncating `transactions`.

## Capabilities

### New Capabilities
- `import-transaction-normalization`: Initialization workflow for rebuilding stock transaction history from imported purchase/sales documents and aligning stock snapshot `ADJ` rows to the normalized ledger.

### Modified Capabilities
- `product-stock-owner-marker-import`: Stock snapshot import must create `ADJ` transactions using the latest normalized transaction ledger balance instead of the pre-import `product_stocks` balance.

## Impact

- Affected code:
  - New Laravel console command for initialization transaction normalization.
  - `Modules/Product/Jobs/ProcessProductImportBatch.php` stock snapshot transaction calculation.
  - Sales and purchase import tests that currently assert no runtime transaction creation.
  - New focused feature tests for normalization, stock-neutral imports, and stock snapshot `ADJ` calculation.
- Affected data:
  - `transactions` is truncated only when the explicit initialization normalization command is run with write/initialize flags.
  - `product_stocks` remains untouched by normalization and remains updated only by product stock snapshot import.
- No new runtime dependencies are expected.
