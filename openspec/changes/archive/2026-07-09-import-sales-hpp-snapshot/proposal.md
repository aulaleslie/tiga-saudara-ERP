## Why

Imported historical sales currently receive sale detail cost snapshots from the live/backfill cost calculation paths, which can differ from the source ledger's average purchase price at the actual sale time. Finance and operational reports use `sale_details.cost_unit_snapshot` for HPP, so imported sales need a controlled post-import correction flow that can load authoritative HPP values from the source ledger.

## What Changes

- Add a product-list import mode for sales HPP snapshots that accepts ledger CSV files like `HPP-2020-S1.csv`.
- Process only rows where `Tipe Transaksi` is `Sales Invoice`.
- Match each CSV row to an already-imported sale detail using source transaction number, product owner marker routing, normalized clean product name, and quantity.
- Overwrite matched sale detail cost snapshots because the uploaded HPP ledger is the source of truth.
- Record batch and row-level results so users can inspect updated rows, skipped/non-sales rows, matching failures, quantity mismatches, and ambiguous matches.
- Preserve existing sales import behavior, imported sale documents, dispatches, stock quantities, inventory transactions, and payment records.

## Capabilities

### New Capabilities
- `sales-hpp-snapshot-import`: Product import workflow for applying authoritative historical HPP snapshots to sale details created by prior sales imports.

### Modified Capabilities
- `sales-cost-snapshots`: Sale detail cost snapshots can be overwritten by an authoritative HPP import source after sales import, while existing live snapshot and backfill behaviors remain intact.

## Impact

- Affected modules: `Modules/Product` import upload, batch processing, import list/detail views, and routes.
- Affected sales data: `sale_details.cost_unit_snapshot`, `sale_details.cost_total_snapshot`, `sale_details.cost_snapshot_source`, and `sale_details.cost_snapshot_at` for matched imported sale details.
- Affected matching rules: reuse or mirror `Modules\Sale\Services\SalesImportService` owner-marker behavior for `*`, ` TP`, unmarked products, and Daizu keywords.
- Affected reports: profit/loss, operational movement, trial balance, and other HPP reports that read sale detail cost snapshots will reflect imported historical HPP after the snapshot import runs.
- No new external dependency is expected; the flow should reuse existing CSV parsing, import batch, Laravel queue, Eloquent, and product import UI patterns.
