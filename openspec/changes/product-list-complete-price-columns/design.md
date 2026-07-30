## Context

The product list DataTable (`ProductDataTable.php`) already performs a `leftJoin` on `product_prices` filtered by the active `setting_id`. The query selects all five price columns (`sale_price`, `tier_1_price`, `tier_2_price`, `last_purchase_price`, `average_purchase_price`), but only three are rendered as visible columns. The two tier prices are fetched but discarded in the view layer.

The table currently has 13 visible columns (image, code, name, category, brand, 5 stock columns, 3 price columns, action). Adding 2 more makes 15, requiring a layout strategy.

The gate `view_access_table_product` is used to conditionally show price columns but is not registered in `app/Config/Permissions.php`. The permission seeder deletes unregistered permissions, making this gate a no-op after every seed.

## Goals / Non-Goals

**Goals:**

- Display `tier_1_price` and `tier_2_price` from the existing joined `product_prices` row in the product DataTable.
- Use abbreviated column headers across all five price columns for compactness.
- Enable horizontal scrolling with the first two columns (image + product code) frozen via CSS `position: sticky`.
- Enable vertical scrolling with a fixed table header via DataTables' native `scrollY` option.
- Register a proper `products.view_prices` permission and replace the orphan `view_access_table_product` gate.

**Non-Goals:**

- Adding new columns to the `product_prices` table (e.g., `last_sale_price`). If a field doesn't exist, it's not displayed.
- Changing the product detail (show) page — it already displays all price fields.
- Rebuilding the DataTables JS/CSS bundle to include FixedColumns or FixedHeader extensions.

## Decisions

### 1. CSS `position: sticky` for frozen columns (not DataTables FixedColumns extension)

The bundled `datatables.min.js` only includes Buttons and Select extensions. FixedColumns would require rebuilding the bundle from datatables.net. CSS `position: sticky` achieves the same effect without new dependencies.

- Sticky-left on columns 0 (image) and 1 (product code) with `left: 0` / `left: 50px`, `z-index: 1`, and a solid background.
- Scoped to `#product-table` to avoid affecting other DataTables.
- Styles injected via `@push('page_css')` on the product index blade.

**Alternative considered**: Rebuilding the DataTables bundle with FixedColumns. Rejected because it requires a build step change and affects all pages loading the global bundle.

### 2. DataTables native `scrollY` for vertical header freeze (not CSS sticky header)

When `scrollY` is set, DataTables splits the `<thead>` into a separate non-scrolling container. This is built-in behavior, no extension needed. Combined with `scrollX: true`, both axes scroll correctly.

Setting `scrollY: '70vh'` with `scrollCollapse: true` ensures the table doesn't waste space when there are few rows.

### 3. Abbreviated price column headers

| Current Header | New Header |
|---|---|
| Harga Beli Terakhir | Beli Akhir |
| Harga Beli Rata Rata | Beli Rata² |
| Harga Jual | Jual |
| *(new)* Harga Jual Partai Besar | Jual Partai |
| *(new)* Harga Jual Reseller | Jual Reseller |

All five columns remain behind the same gate check.

### 4. Permission name: `products.view_prices`

Follows the existing `products.<action>` naming convention in `app/Config/Permissions.php`. Placed in the `'Produk'` group with label `'Lihat Harga'`.

The orphan `view_access_table_product` is removed from code entirely. After the next seed, Admin role gets the new permission automatically.

## Risks / Trade-offs

- **[Risk] CSS sticky may have z-index conflicts with DataTables scrollY wrapper** → Mitigation: scope all sticky styles to `#product-table` and test that the DataTables-generated scroll wrapper doesn't override `position: sticky`. DataTables' `scrollY` clones the header into a separate `<div>`, so sticky-left on body cells won't conflict with the cloned header.
- **[Risk] Existing non-Admin users lose price visibility after seed** → Mitigation: this is an existing bug (the orphan permission already gets deleted on seed). Registering `products.view_prices` and assigning it explicitly fixes the underlying issue. Document in release notes.
- **[Risk] 15 columns on narrow screens** → Mitigation: horizontal scroll with frozen identifier columns ensures usability. The `table-responsive` wrapper already provides a scrollbar.
