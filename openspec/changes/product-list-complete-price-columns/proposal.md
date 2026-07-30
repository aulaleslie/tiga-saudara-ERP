## Why

The product list DataTable already joins `product_prices` for the active `setting_id` and selects all five price columns, but only displays three: Harga Beli Terakhir, Harga Beli Rata-rata, and Harga Jual. The tier prices (`tier_1_price`, `tier_2_price`) are fetched but invisible. Users must click into each product to see Harga Jual Partai Besar and Harga Jual Reseller. Additionally, the gate `view_access_table_product` used to guard price columns is not registered in the centralized permission config and gets deleted on every seed run.

## What Changes

- Display the two missing price columns in the product DataTable: **Harga Jual Partai Besar** (tier 1) and **Harga Jual Reseller** (tier 2), sourced from the same active-setting `product_prices` row already joined.
- Abbreviate all five price column headers for compactness (e.g., "Beli Akhir", "Beli Rata²", "Jual", "Jual Partai", "Jual Reseller").
- Register a proper permission `products.view_prices` in the centralized config and replace the orphan `view_access_table_product` gate.
- Enable horizontal scrolling (`scrollX`) with CSS `position: sticky` on the first two columns (image + product code) so they remain frozen while scrolling right.
- Enable vertical scrolling (`scrollY`) so the table header stays fixed when scrolling down through rows.
- Empty/null values display `-`, never values from another setting.

## Capabilities

### New Capabilities

- `product-list-price-columns`: Expose all active-setting price fields (including tier prices) in the product DataTable with frozen-column horizontal scroll and sticky vertical header.

### Modified Capabilities

- `product-creation`: The permission gate for viewing prices in the product list changes from orphan `view_access_table_product` to registered `products.view_prices`.

## Impact

- **Files**: `ProductDataTable.php` (column definitions, query unchanged), product index Blade (CSS for sticky columns), `app/Config/Permissions.php` (new permission entry).
- **No migration**: The `product_prices` schema already contains all needed columns.
- **No model change**: `ProductPrice` model already exposes all fields.
- **Permission**: Existing users with `view_access_table_product` will need `products.view_prices` assigned after the next seed. Admin role gets it automatically via seeder sync.
