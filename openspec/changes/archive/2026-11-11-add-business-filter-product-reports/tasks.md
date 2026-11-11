# Tasks

## 1. Shared business scope and security fixes

- [x] 1.1 Change `HasReportSettingScope::getAvailableSettings()` to return the user's assigned businesses (all for Super Admin), ordered by company name
- [x] 1.2 Add `getSanitizedSettingIds()` to the trait (intersect with available, cast to int, unique, sorted, reindexed)
- [x] 1.3 Make `validateSettingIds` usage in SaleByProduct, P&L, GL, TB, CF and BS go through the sanitized set; keep their empty → session fallback
- [x] 1.4 Replace the local scope helpers in `CrossBusinessStockInventory` with the trait, keeping its "all assigned" default
- [x] 1.5 Feature test: a user assigned to A+B sees only A and B in SaleByProduct and P&L options; a tampered ID for C is dropped; Super Admin sees all
- [x] 1.6 Add the `inventoryValuationReports.access` gate to `WarehouseStockValuationReportController`, and update `WarehouseStockValuationReportAccessTest` with a denied case
- [x] 1.7 Limit `StockInsightsQueryService` (business hierarchy, stock matrix, Stok Global, attention counts, sales aggregates) to the assigned businesses passed in from `StockInsights`
- [x] 1.8 Feature test: Pantauan Stok for a user assigned to A+B excludes C from columns, Stok Global and sales measures; Super Admin includes all

## 2. Mutasi Stok

- [x] 2.1 Add the trait and `selectedSettingIds` to `StockMutationReport`; `mount()` defaults to the session business, or all assigned when `isGlobal`
- [x] 2.2 Filter transactions with `whereIn` on the selected businesses, replacing the `isGlobal` / single-setting branch; show "Pilih minimal satu bisnis" when the selection is empty
- [x] 2.3 Build the purchase and sale reference date maps per business (keyed by setting + reference)
- [x] 2.4 Make location options the union over the selected businesses with business labels; prune `locationId` when businesses change
- [x] 2.5 Add the business selector partial to the view, and a `Bisnis` column shown only when more than one business is selected
- [x] 2.6 Add the Bisnis column to the CSV/XLSX exports when more than one business is selected
- [x] 2.7 Feature tests: unchanged single-business output; A+B shows the Bisnis column; Global defaults to the assigned businesses only; a tampered ID is dropped

## 3. Matrix reports (Ringkasan, Kuantitas stok gudang, Nilai stok gudang)

- [x] 3.1 Change `InventorySummaryReportQueryService` to `array $settingIds`: product candidates come from stock at the selected businesses' active locations (no `products.setting_id`), each business is computed with its own price map, and the result is aggregated into Total + per-business
- [x] 3.2 Update `InventorySummaryReport` (trait, defaults, empty guard, category and product pickers without `setting_id`, sort on Total) and render the matrix when more than one business is selected
- [x] 3.3 Inventory summary export: unchanged columns for one business; Total + `<Bisnis> — <col>` columns and totals for several
- [x] 3.4 Change the warehouse stock quantity query to multiple businesses with per-business totals and per-warehouse breakdown; drop the product ownership filter
- [x] 3.5 Update `WarehouseStockQuantityReport`: trait, warehouse options across businesses with pruning, matrix view with expandable business groups, export with all columns expanded
- [x] 3.6 Change the warehouse stock valuation query to multiple businesses using each business's average cost; Total value = sum of the business values; drop product and category ownership filters
- [x] 3.7 Update `WarehouseStockValuationReport`: trait, warehouse and category options, matrix view with expandable business groups, grand total, export
- [x] 3.8 Feature tests for each of the 3 reports: options limited to assigned businesses; tampered ID dropped; single-business output unchanged; Total = Σ business values; a product owned by B but stocked at A appears when only A is selected
- [x] 3.9 Update existing fixtures and tests that relied on `products.setting_id` scoping

## 4. Ledger reports (Nilai persediaan, Detail persediaan)

- [x] 4.1 Change `InventoryValuationReportQueryService` to `array $settingIds`: replay the opening balance, running quantity, weighted-average cost and ledger per business (all locations, including inactive), then aggregate the summary to Total + per-business
- [x] 4.2 Make `getProductDetail` return a ledger keyed by business; update `InventoryValuationReport` (`loadedProductDetails[productId][settingId]`), the view (business sub-groups with their own Saldo Awal and subtotal) and the summary columns
- [x] 4.3 Inventory valuation export grouped by business; unchanged for one business
- [x] 4.4 Apply the same changes as 4.1 to 4.3 to `InventoryDetailReportQueryService`, `InventoryDetailReport`, its view and export (quantity only)
- [x] 4.5 Make category and product pickers in both reports query the catalog without `setting_id`
- [x] 4.6 Feature tests for both reports: per-business running balances never mix; grand total = Σ business values; single-business output unchanged; tampered ID dropped; product owned elsewhere appears
- [x] 4.7 Update existing tests and fixtures that relied on `products.setting_id` scoping

## 5. Verification

- [x] 5.1 Run `composer test:fresh-sqlite`, or `php artisan test --filter` over Reports, StockInsights, CrossBusiness, SaleByProduct and ProfitLoss
- [x] 5.2 Run `openspec validate add-business-filter-product-reports --strict`
- [x] 5.3 Manual browser check of each of the 7 Produk reports with 1 and with 2 businesses selected, as a non–Super Admin user

## 6. Pre-production refinements: selector redesign, drawer cancel decoupling, and Indonesian labels

- [x] 6.1 Remove `selectedSettingIds = ...appliedFilters...` from `cancelFilters()` across 5 reports; prune warehouse IDs against current business selection in warehouse reports
- [x] 6.2 Create `business-checkbox-selector.blade.php` with fixed 38px trigger, text truncation, search if > 6 options, select all/clear all, outside-click sync, without `wire:ignore` or select2
- [x] 6.3 Replace `business-source-selector` across all 7 Produk reports with `.report-business-slot` and the new checkbox selector partial
- [x] 6.4 Create `InventoryTransactionTypeLabel` covering all 21 types, TRF/ADJ delta direction, and unknown fallback; wire into `StockMutationReport`, `StockMutationReportExport`, `InventoryDetailReportQueryService`, `InventoryValuationReportQueryService`, and clean up serial dialog condition labels
- [x] 6.5 Add unit and feature tests for cancelFilters decoupling, selector rendering, and Indonesian labels

