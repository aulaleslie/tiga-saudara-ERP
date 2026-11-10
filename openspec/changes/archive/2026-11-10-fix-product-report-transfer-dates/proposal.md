# Proposal

## Why

Produk reports fail when replay loads transfers because queries select `transfers.transfer_date`, a column absent from repository migrations. Transfer dates must also reflect the corresponding stock movement so missing receipt dates cannot place incoming stock into the dispatch period.

## What Changes

- Remove the nonexistent column from shared replay and warehouse quantity metadata queries.
- Date outgoing TRF movements by `dispatched_at` and incoming TRF movements by `received_at`; fall back directly to the inventory transaction's `created_at` when the corresponding timestamp is missing.
- Apply the correction to inventory summary, detail, valuation, warehouse quantity, and warehouse valuation reports, including their shared export paths.
- Inspect other active Produk report paths for this defect and use focused regression verification only.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `inventory-detail-report`: Specify transfer movement dates and missing timestamp fallback.
- `inventory-valuation-report`: Specify transfer dates for period allocation and replay.
- `warehouse-stock-quantity-report`: Specify transfer dates for warehouse as-of balances.
- `warehouse-stock-valuation-report`: Specify transfer dates for warehouse valuation replay.

## Impact

Changes target `InventoryReplaySupport` and `WarehouseStockQuantityReportQueryService`, with focused report tests. Inventory summary receives the shared correction. Other Produk reports are checked for the same query defect, without assuming every reported failure has the same cause. No schema migration, historical data rewrite, stock mutation, permission change, or new dependency is required.
