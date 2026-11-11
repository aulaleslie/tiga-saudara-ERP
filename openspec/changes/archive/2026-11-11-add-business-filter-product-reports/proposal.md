# Proposal

## Why

Reports under `Laporan → Produk` can only show the active session business. Users who work across several businesses must switch sessions to compare stock and value. The reports also filter on `products.setting_id`, which is now only an ownership marker on a global catalog, so they hide stock a business holds of products owned by another business. Separately, the shared report scope helper (`HasReportSettingScope`) offers **every** business to any permitted user, and the global product reports ignore business assignment entirely. Both expose data the user is not assigned to.

## What Changes

- Add a multi-select **Bisnis** filter to the six single-business Produk reports: Mutasi Stok, Ringkasan persediaan, Kuantitas stok gudang, Nilai persediaan, Nilai stok gudang, Detail persediaan. Options are the businesses assigned to the user (all for Super Admin). The default is the session business. No new permission; each report keeps its existing access permission.
- Make the shared report scope helper return only assigned businesses and drop any submitted business ID outside that set. **BREAKING (security):** users of Penjualan per produk, Laba Rugi and the operational GL/TB/CF/BS reports lose access to businesses they are not assigned to.
- Limit Mutasi Stok Global and Pantauan Stok to assigned businesses. Mutasi Stok Global becomes Mutasi Stok with all assigned businesses pre-selected.
- Stop the six reports from reading `products.setting_id` and `categories.setting_id`. Products and categories are treated as global. Which products appear depends on stock and movements in the selected businesses' locations.
- Change layouts by report type:
  - Snapshot reports (Ringkasan, Kuantitas stok gudang, Nilai stok gudang) use a Lintas Bisnis-style matrix: a Total column group plus one column group per business. Warehouse reports can expand each business to its warehouses.
  - Ledger reports (Nilai persediaan, Detail persediaan) show a product row with Total and per-business columns. The expanded ledger is grouped by business, with its own opening balance, running balance and closing balance.
  - Mutasi Stok stays a flat list with a Bisnis column.
  - With a single business selected, every report looks and exports as it does today.
- Value each business with its own average cost. Totals are the sum of the per-business values.
- Exports match the screen layout.
- Add the missing `inventoryValuationReports.access` gate to `WarehouseStockValuationReportController`.

## Capabilities

### New Capabilities
- `report-business-scope`: the shared rule for which businesses a report may offer, the default selection, sanitizing submitted IDs, the empty-selection guard, and dependent filter options (location/category/product).
- `stock-mutation-report`: Mutasi Stok and Mutasi Stok Global, with business scope and a Bisnis column.
- `inventory-summary-report`: Ringkasan persediaan barang, with business scope and a matrix layout.

### Modified Capabilities
- `inventory-valuation-report`: scoped to selected businesses instead of the active setting, with a per-business ledger and valuation.
- `inventory-detail-report`: scoped to selected businesses, with a per-business ledger.
- `warehouse-stock-quantity-report`: scoped to selected businesses, matrix with expandable warehouses.
- `warehouse-stock-valuation-report`: scoped to selected businesses, matrix, enforced access gate.
- `stock-insights-report`: global and business columns limited to the businesses assigned to the user.

## Impact

- Livewire: `app/Livewire/Reports/{StockMutationReport, InventorySummaryReport, WarehouseStockQuantityReport, InventoryValuationReport, WarehouseStockValuationReport, InventoryDetailReport, StockInsights, HasReportSettingScope}.php`, plus the `CrossBusinessStockInventory` local helper, which moves onto the shared trait.
- Services: `app/Services/Reports/{InventorySummary,InventoryValuation,InventoryDetail}ReportQueryService.php`, the warehouse report query code, `StockInsightsQueryService::getBusinessHierarchy`. Signatures change from `int $settingId` to `array $settingIds`.
- Views and exports for the six reports under `resources/views/livewire/reports/` and `app/Exports/`.
- Controller: `Modules/Reports/Http/Controllers/WarehouseStockValuationReportController.php`.
- Indirect: Penjualan per produk, Laba Rugi and the operational GL/TB/CF/BS reports now offer only assigned businesses.
- Out of scope: dropping the `products.setting_id` / `categories.setting_id` columns, category normalization, and other modules (POS, import, product CRUD).
