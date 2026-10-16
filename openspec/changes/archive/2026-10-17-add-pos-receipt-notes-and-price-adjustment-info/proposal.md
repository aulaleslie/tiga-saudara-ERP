# Proposal

## Why

POS receipts omit the existing transaction note and do not explain deliberate price adjustments to linked Sales. Customers paying outstanding debt need a current receipt that explains the revised transaction prices and shows the authoritative remaining balance, without being affected by future catalog changes.

## What Changes

- Print the existing POS transaction note as `Catatan`, after payments and before return history, with escaped multiline text and no empty section.
- Explain proven unit selling-price differences between completed checkout and current linked Sales beneath affected standard items; group split Sales by distinct current price and quantity.
- For packed items, show `Harga penjualan diperbarui` when a proven blended unit-price change exists, without inventing separate current box/loose prices.
- For fully returned standard items, compare checkout price with the saved price at return in return history and label that endpoint explicitly.
- Verify current total, payment rows, and outstanding debt after Sales adjustments and later payments; reuse existing settlement evidence and narrowly correct receipt projection gaps if needed.
- Historical receipts include the transaction note but retain original checkout facts and omit adjustment notices. Draft/loaded receipts show their existing transaction note without completed-price comparisons.
- Use focused verification only; no full-suite test requirement.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-receipt`: Transaction notes, proven transaction-price adjustment information, historical behavior, and current financial reconciliation after Sales price adjustments.

## Impact

Primarily `PosReceiptService`, `PosReceiptReprintProjectionService`, the shared `pos::receipt` Blade template, and focused POS receipt tests. Reuse persisted transaction lines, Sale details, return snapshots and the existing settlement projection. No schema changes, Sales editing redesign, catalog pricing changes, new audit history, or historical backfill are planned. Existing return behavior, authorization and print audit conventions remain in force.
