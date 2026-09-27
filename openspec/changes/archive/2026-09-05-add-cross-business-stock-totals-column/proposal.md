## Why

Users comparing stock across businesses on the Cross Business Stock Inventory report currently have to manually add up Good/Bad quantities across every visible business column per product row. The underlying totals (`total_good`, `total_bad`) are already computed per row in `CrossBusinessStockInventoryQueryService::buildRows()`, scoped to the currently selected businesses, but are never rendered on screen or in the Excel export.

## What Changes

- Add two new columns, "Total Bagus" and "Total Rusak", positioned immediately after the "Merek" (Brand) column, on both the on-screen table and the Excel export.
- Each column shows the sum of that product's Good (or Bad) quantity across all currently selected/visible businesses, independent of whether any individual business column is collapsed or expanded to per-location detail.
- No changes to filtering, permissions, pagination, or the underlying query/aggregation logic — the totals are already computed and business-scoped; this only surfaces them.
- Excel export mirrors the same two columns in the same position, vertically merged across all header tiers like the existing Produk/Kategori/Merek columns.

## Capabilities

### New Capabilities

(none)

### Modified Capabilities

- `cross-business-stock-inventory`: Adds a requirement that the report and its Excel export display a cross-business total (Good and Bad) per product row, in addition to the existing per-business Good/Bad breakdown.

## Impact

- `resources/views/livewire/reports/cross-business-stock-inventory.blade.php` — add 2 header cells (tier 1, rowspan 2) and 2 data cells per row.
- `app/Exports/CrossBusinessStockInventoryExport.php` — insert 2 fixed columns after Merek, shift business/location column indexing accordingly, extend cell merges.
- `app/Services/Reports/CrossBusinessStockInventoryQueryService.php` — no changes; `total_good`/`total_bad` already exist on each row.
- `app/Livewire/Reports/CrossBusinessStockInventory.php` — no changes.
