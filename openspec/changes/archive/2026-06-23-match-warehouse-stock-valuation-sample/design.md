## Context

The Reports module currently has three adjacent inventory reports:

- `InventoryValuationReport` is an active detailed valuation ledger. It groups by product and expands opening balance, transaction rows, per-product subtotal, and grand total over a date range.
- `WarehouseStockQuantityReport` is an active as-of warehouse quantity report. It computes product quantities per selected warehouse and explicitly remains quantity-only.
- The Reports landing page has a `Nilai stok gudang` card, but it is a placeholder.

The sample files under `report-sample/nilai-stock-gudang` describe a different report shape: a read-only as-of warehouse stock valuation snapshot. The UI shows `Nilai stok gudang (dalam IDR)`, date/period/warehouse filters, advanced product/category filters, a warehouse-grouped product table, CSV/XLSX exports, and a note that inventory value uses average cost. The CSV is flat product rows, while the XLSX includes metadata rows, a warehouse grouping row, product rows, and a total row.

## Goals / Non-Goals

**Goals:**
- Implement `Nilai stok gudang` as a first-class Reports module page reachable from the existing landing card.
- Produce sample-aligned on-screen, CSV, and XLSX report shapes.
- Calculate quantities as of one selected date, per selected warehouse, scoped to the active setting.
- Calculate each row's stock value as warehouse quantity multiplied by the product average cost used by the ERP.
- Preserve existing detailed inventory valuation and quantity-only warehouse stock report behavior.
- Keep the report read-only and avoid schema changes.

**Non-Goals:**
- Replacing or rewriting `InventoryValuationReport`.
- Adding valuation columns to `WarehouseStockQuantityReport`, whose existing spec requires it to remain quantity-only.
- Implementing PDF export or external Mekari integration.
- Mutating stock, normalizing product prices, or replaying historical inventory into new tables.
- Introducing owner/global cross-setting reporting in this change.

## Decisions

### Add a new report instead of reshaping existing reports

Create a separate `WarehouseStockValuationReport` Livewire component, query service, filter data object, export class, route, controller, and view. The Reports landing card should link to the new route instead of remaining a placeholder.

Rationale: the existing valuation report is a transaction ledger with date range semantics, and the existing warehouse quantity report is explicitly quantity-only. The sample is an as-of snapshot with valuation by warehouse. A separate report keeps all three contracts coherent.

Alternative considered: reshape `InventoryValuationReport` to match the sample. Rejected because that would break the existing ledger-oriented spec and tests.

Alternative considered: extend `WarehouseStockQuantityReport` with average cost/value columns. Rejected because the archived spec says it SHALL NOT include valuation output.

### Reuse as-of quantity replay patterns

The new query service should reuse the transaction-date and location-quantity approach from `WarehouseStockQuantityReportQueryService`: filter active-setting stock-managed products, resolve selected warehouses, replay transactions through the as-of end-of-day cutoff, and retain zero or negative quantities.

Rationale: the sample is warehouse-specific and as-of dated. Existing `product_stocks` gives current quantity snapshots, but historical as-of reporting needs transaction replay so future stock movements are excluded.

Alternative considered: read directly from `product_stocks`. Rejected for as-of dates before current stock state.

### Use product average cost as the valuation source

Each row's `Harga rata-rata` should come from the active setting's `ProductPrice.average_purchase_price` where available, falling back to the product's legacy `average_purchase_price` or equivalent existing accessor behavior. `Nilai stok` is `qty * average_cost`.

Rationale: the sample labels the method as average cost and does not show per-warehouse cost layers. The current codebase already stores and normalizes average purchase price per product/setting.

Alternative considered: replay weighted average cost from all historical transactions up to the as-of date for every row. This would be closer to a full historical valuation engine, but it is heavier and does not map cleanly to per-warehouse cost unless the business requires warehouse-specific costing.

### Keep CSV and XLSX intentionally different

CSV should be machine-friendly flat rows with Indonesian headers:
`Gudang`, `Kode Produk`, `Nama Produk`, `Qty`, `Min. Qty`, `Satuan Produk`, `Harga Rata-rata`, `Nilai Persediaan`.

XLSX should be presentation-friendly with title/date metadata, merged title rows, `Gudang/Kode Produk` table header, warehouse grouping rows, product rows, and a final `Total` row. Totals should be computed by the export data, not spreadsheet formulas.

Rationale: the sample files use different shapes for CSV and XLSX. Matching that distinction avoids forcing spreadsheet layout concerns into CSV.

### Product status filters are derived from as-of quantity and minimum stock

The advanced product filter should support:

- all products
- only out-of-stock products (`qty == 0`)
- only available-stock products (`qty > 0`)
- only products below minimum stock (`qty <= min_qty`)

Use the selected warehouses' aggregate quantity for product-status filtering unless implementation discovers that the sample source applies the filter per warehouse group. Negative quantities satisfy below-minimum and are not clamped.

Rationale: the sample labels are product-level stock status filters, while the report rows are grouped by warehouse. Aggregating selected warehouses is predictable and aligns with the selected report scope.

### Category match mode remains single-category aware

The UI should expose `Mencakup semua` and `Salah satu` for sample parity. Because current products have one `category_id`, `Salah satu` means products in any selected category. `Mencakup semua` can only match when a product belongs to every selected category, which effectively means one selected category unless future multi-category product support appears.

Rationale: this preserves sample UI language and current data model constraints without inventing new category relationships.

## Risks / Trade-offs

- Historical average cost may differ from current stored average cost for old as-of dates -> Document this as an average-cost snapshot based on the ERP average purchase price source, and add tests around fallback behavior. Escalate to full historical weighted-average replay only if the business requires dated cost reconstruction.
- Replaying transactions for all products and selected warehouses can be expensive on large datasets -> Filter by active setting, selected warehouse, selected product/category where possible before replaying; paginate in memory only after the result set is built; add focused performance tests or query-count review if implementation shows stress.
- Product status filtering after aggregate calculation may surprise users expecting per-warehouse status -> Keep the behavior explicit in tests and copy; if business asks for per-warehouse filtering later, it can be added as a separate requirement.
- Existing permissions may be too broad or too narrow -> Start with `inventoryValuationReports.access` because this is a valuation report and the landing placeholder already uses that permission; adjust only if the local permission matrix indicates a specific warehouse valuation permission.
- XLSX formatting can drift from the sample -> Assert key workbook values, headers, metadata rows, grouping rows, and total row in export tests instead of relying on visual inspection alone.

## Migration Plan

1. Add the new report route, controller, Livewire component, query/filter services, view, export class, and landing card link.
2. Keep the existing placeholder visible only as the new actionable report card; do not remove or rename existing inventory valuation or warehouse quantity routes.
3. Deploy without database migrations.
4. Rollback by reverting the new route/card/component/export files; existing reports remain unaffected.

## Open Questions

- Should `Nilai stok gudang` display warehouse grouping even when multiple warehouses are selected, or should selected warehouses be flattened into one aggregate view? The sample groups by warehouse and should be treated as the default.
- Should average cost be reconstructed as of the selected date or read from the current product price row? This design chooses the stored ERP average cost source for first implementation.
- Should product code stay blank in exports when missing, matching the sample CSV, while UI displays `-`? This design recommends yes.
