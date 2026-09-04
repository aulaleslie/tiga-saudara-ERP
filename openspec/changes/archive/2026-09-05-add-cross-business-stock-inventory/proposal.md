## Why

Users holding `inventory.view_remaining_stock` currently have no single place to see remaining stock for a product across all businesses they're assigned to. Checking stock today means switching business context repeatedly and re-searching per business, with no visibility into good-vs-broken condition, tax/non-tax composition, or available-to-sell serial numbers in one view.

## What Changes

- New menu/report: cross-business stock inventory, gated by the existing `inventory.view_remaining_stock` permission.
- One row per product; columns spread per business the acting user is assigned to (via the `user_setting` pivot), with Super Admin seeing all businesses unconditionally.
- Each business column is collapsible to a Good/Bad subtotal or expandable to per-location Good/Bad columns (a business can have multiple warehouse locations).
- Good = `quantity_tax + quantity_non_tax`; Bad = `broken_quantity_tax + broken_quantity_non_tax`, read from `product_stocks`.
- A tooltip surfaces tax/non-tax composition against the business's `is_pkp` flag (e.g., non-tax quantity present on a PKP business) at both the collapsed (aggregated) and expanded (per-location) levels — informational only, never validated or blocked.
- For serialized products (`products.serial_number_required`), a button on each Good/Bad cell opens a dialog listing serial numbers for that specific business/location/condition, defaulting to the existing "sellable" filter (not broken, not in return, not dispatched, not returned).
- Single search box: tokenized multi-word search across product name/code/category/brand (existing `Product::scopeGlobalSearch` pattern, unchanged), plus a separate exact-match lookup path for barcode and serial number.
- Filters: business multi-select (scoped to user's assigned businesses, defaults to all assigned), category (live-search, any/all match mode), brand (live-search, same pattern), availability (all/available/non-available), pagination.
- Excel export mirrors the UI layout exactly but always fully expanded to per-location detail, regardless of on-screen collapse state.
- Non-breaking addition: a plain BTREE index on `products.barcode` to support the exact-match barcode lookup path.

## Capabilities

### New Capabilities
- `cross-business-stock-inventory`: A paginated, filterable, exportable report showing per-product remaining stock (good/broken, tax/non-tax) across all businesses a user is assigned to, with collapsible per-location detail and a drill-down dialog for sellable serial numbers.

### Modified Capabilities
(none — no existing capability's requirements change; this is a net-new report reusing existing permission and data without altering their behavior)

## Impact

- **New Livewire component + view**: a new report page under `app/Livewire/Reports/` (or equivalent) and its blade view, added to the reports menu gated by `inventory.view_remaining_stock`.
- **New Excel export class**: mirrors `ProfitLossReportExport`/`InventoryDetailReportExport` conventions.
- **New migration**: adds a plain BTREE index on `products.barcode` (additive, non-breaking).
- **Read-only against existing tables**: `products`, `product_stocks`, `product_serial_numbers`, `locations`, `settings`, `categories`, `brands` — no writes, no schema changes to existing columns.
- **Reused, not modified**: `inventory.view_remaining_stock` permission, `Product::scopeGlobalSearch` token logic, `ProductSerialNumbersTable` sellable-serial filter logic, `business-source-selector.blade.php` UI component, `InventoryDetailReport`'s category live-search pattern.
- **No changes** to `HasReportSettingScope` or any of the 6 existing reports using it — business-scoping for this new component is implemented locally, not by modifying the shared trait.
