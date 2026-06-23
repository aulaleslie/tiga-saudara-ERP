## Why

The Reports landing page already advertises `Nilai stok gudang`, but it is still a placeholder while the provided Mekari-style sample shows a concrete warehouse stock valuation report that users expect. Adding this report closes that product gap without disturbing the existing detailed inventory valuation ledger or the quantity-only warehouse stock report.

## What Changes

- Add a new warehouse stock valuation report reachable from Reports > Produk via the existing `Nilai stok gudang` card.
- Provide an as-of date report titled `Nilai stok gudang (dalam IDR)` with sample-aligned filters for period, warehouse, product stock status, product category, category match mode, and warehouse name order.
- Render warehouse-grouped product rows with SKU, product name, warehouse stock, minimum stock, unit, average cost, stock value, and a grand stock value total.
- Export the full filtered report to CSV and XLSX using the sample-aligned shapes: flat CSV rows and formatted XLSX metadata/grouping/total rows.
- Keep the report read-only and scoped to the active setting.
- Preserve existing `inventory-valuation-report` ledger behavior and existing `warehouse-stock-quantity-report` quantity-only behavior.

## Capabilities

### New Capabilities
- `warehouse-stock-valuation-report`: Covers the new `Nilai stok gudang` report, including access, filters, as-of warehouse valuation calculation, table presentation, CSV export, XLSX export, and read-only boundaries.

### Modified Capabilities
- None.

## Impact

- Affected code: `Modules/Reports` routes/controllers/landing card, new or updated Livewire report component under `app/Livewire/Reports`, report view under `resources/views/livewire/reports`, query/filter services under `app/Services/Reports`, and export class under `app/Exports`.
- Affected data sources: `products`, `product_prices`, `product_stocks`, `transactions`, `locations`, categories, and active `setting_id` session scope.
- Affected permissions: reuse `inventoryValuationReports.access` for the valuation report unless implementation discovers an established narrower permission pattern.
- No database migrations, no stock mutation behavior changes, and no changes to POS, Sales, Purchase, or product import lifecycles.
