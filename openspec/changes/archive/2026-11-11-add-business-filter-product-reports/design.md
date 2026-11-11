# Design

## Context

See proposal.md for the motivation. The current state that shapes the approach:

- **Livewire components** in `app/Livewire/Reports/` read `session('setting_id')` into a single `$settingId`. `WarehouseStockValuationReport` and `WarehouseStockQuantityReport` load `Location::where('setting_id', …)` in `mount()`.
- **Query services** (`InventorySummary`, `InventoryValuation`, `InventoryDetail`) take `int $settingId` and start from `Product::where('setting_id', $settingId)`. Price maps and purchase/sale date maps are also keyed by one setting.
- **`HasReportSettingScope`** (used by SaleByProduct, P&L, the operational reports) returns `Setting::all()`. `CrossBusinessStockInventory` has its own copy of the correct rule (Super Admin → all, else `$user->settings()`), and so does `BusinessSelector`.
- **Shared selector UI:** a multi-select partial already drives `selectedSettingIds` in Lintas Bisnis and Penjualan per produk.
- **Data model:** stock lives in `product_stocks` per location, and `locations.setting_id` is the business. Average and other prices live in `product_prices` per (product, setting). Products and categories are a global catalog. Their `setting_id` is ownership only and is treated as deprecated.

## Goals / Non-Goals

**Goals:**
- One server-side source of truth for "businesses this user may see", used by every report.
- Keep the single-business output byte-compatible with today's output, which existing tests guard.
- Per-business computation first, aggregation second, so valuation never blends costs.

**Non-Goals:**
- Schema changes, or dropping `setting_id` columns.
- Category normalization (we assume categories are global).
- A business selector on Pantauan Stok.
- Performance work beyond what the multi-business queries need (indexes already exist on `locations.setting_id`).

## Decisions

### D1. Fix the trait instead of adding a new helper
`HasReportSettingScope::getAvailableSettings()` adopts the Super Admin / `$user->settings()` rule. Add `getSanitizedSettingIds()`: the intersection of the selection with the available businesses, normalized to sorted ints. `CrossBusinessStockInventory` and `StockInsights` switch to the trait. `getEffectiveSettingIds()` keeps its "empty → session" fallback only for the existing sales and finance reports. The Produk reports instead initialize `selectedSettingIds = [session]` in `mount()` and treat empty as the "Pilih minimal satu bisnis" state.
*Alternative:* a new trait just for Produk reports. Rejected, because it would leave the leak in the other reports and keep two rules.

### D2. Services take `array $settingIds` and compute per business
Each service changes its signature to `array $settingIds`. The internal flow:

```
selected businesses ─▶ location ids (active for snapshots; all for ledgers)
                    ─▶ candidate products = products with product_stocks / transactions
                        at those locations  (no products.setting_id filter)
for each business b:  compute rows exactly as today, but with b's locations
                       and b's product_prices / reference date maps
aggregate:            Total = Σ per-business rows; keep per-business breakdown
```

Each business's computation reuses today's single-setting code path, wrapped in a loop. This is also what keeps the single-business output identical: with one business, the result is that business's rows and the Total equals them. The return shape gains `businesses: [settingId => [...]]` per product, plus `totals`.
*Alternative:* a single SQL query grouping by `locations.setting_id`. Viable for snapshots, but the ledger replay (running weighted-average cost) is done in PHP and is per business by nature. Using one loop pattern everywhere is simpler to verify.

### D3. Removing the product ownership filter
Replace `Product::where('setting_id', $id)` with `Product::whereIn('id', <product ids from product_stocks/transactions at selected locations>)`, keeping the `stock_managed`/active filters. Category and product option pickers query the catalog without `setting_id`. This changes the single-business row population as well (products owned elsewhere but stocked here now appear). This is intended per the spec. Single-business **layout** is unchanged, and existing tests whose fixtures rely on ownership are updated.

### D4. Rendering by report type
- Matrix (Ringkasan, Kuantitas stok gudang, Nilai stok gudang): reuse the Lintas Bisnis header pattern (Total group, a group per business, `expandedBusinesses[settingId]` toggles for warehouse columns). Render the matrix only when `count(selected) > 1`; otherwise render today's table.
- Ledger (Nilai persediaan, Detail persediaan): the product summary row gets Total plus per-business columns. The lazy-loaded `loadedProductDetails[productId]` becomes `[settingId => ledger]`, rendered as business sub-groups.
- Mutasi Stok: add a `Bisnis` column (from `location.setting`) when more than one business is selected. Mutasi Stok Global = the same component with `selectedSettingIds` initialized to every available business. The `isGlobal` flag then only drives the default and the filename.

### D5. Dependent filters
Warehouse options = `Location::whereIn('setting_id', sanitized)`, labelled `name — company_name` when more than one business is selected. `updatedSelectedSettingIds()` prunes `warehouseIds`/`locationId` that are not among the new options and resets pagination. Export filter hashes include the normalized setting IDs, following the existing SaleByProduct hashing pattern.

### D6. Exports
Exports receive the same `settingIds` and reuse the service output. With one business, the columns are unchanged. With several, the per-business column groups are flattened into headers like `A — Stok`, `A — Nilai`. Warehouse columns are always expanded in exports.

## Risks / Trade-offs

- [Behaviour change for single-business users: products owned by other businesses now appear] → This is intended (spec). It is called out in the release notes, and covered by a test.
- [The trait fix narrows sales and finance report options for non–Super Admin users who relied on seeing every business] → This is intended (a security fix). Assignments can be granted where needed.
- [Per-business loops multiply query cost by the number of businesses selected] → The default is one business. Product-id preselection limits the work. Each loop reuses the existing batched maps. Measure on the largest tenant before step 4.
- [Wide matrices with many businesses × warehouses] → Warehouses are collapsed by default, with sticky product columns as in Lintas Bisnis.
- [Existing tests assert `setting_id` product scoping] → Update fixtures in the same task as each service.

## Migration Plan

No schema migration. The work ships in the task phases, and each phase is deployable on its own: (1) scope and security, (2) Mutasi Stok, (3) the matrix reports, (4) the ledger reports. To roll back, revert the phase's commits. No data is changed.
