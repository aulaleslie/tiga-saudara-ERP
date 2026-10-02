# Proposal

## Why

The Purchase by Product report (`PurchaseByProductReportQueryService`) produces duplicate rows for the same product when `purchase_details.unit_name` has mixed `NULL`/non-`NULL` values across rows. The SQL `GROUP BY unit_name` alias collides with the `purchase_details.unit_name` column — MySQL 8 resolves the unqualified `unit_name` in `GROUP BY` to the table column instead of the `COALESCE(...)` alias, splitting identical products into separate groups that display identically on screen.

The broader design issue: the query groups by snapshot columns (`purchase_details.product_code`, `purchase_details.product_name`) that can diverge from the master `products` table over time, even though the report always displays master product data via `COALESCE`. Grouping should use `product_id` only and derive display values from the `products` table.

## What Changes

- Fix GROUP BY to use `purchase_details.product_id` as the sole grouping key, eliminating the alias collision and snapshot-divergence issues
- Ensure SELECT expressions for `product_code`, `product_name`, and `unit_name` are derived from the `products`/`units` master tables (already the case for display, but now consistent with grouping)

## Capabilities

### New Capabilities

_(none)_

### Modified Capabilities

- `purchase-by-product-report`: The product aggregate grouping requirement changes from "one row per product-and-unit combination using snapshot columns" to "one row per `product_id`, with display values derived from the products master table." This eliminates the MySQL 8 alias collision bug and prevents snapshot-divergence duplicates.

## Impact

- `app/Services/Reports/PurchaseByProductReportQueryService.php` — GROUP BY clause change
- No migration, no schema change, no new dependencies
- Existing export (Excel/CSV), grand total, and sort logic work unchanged because the SELECT aliases remain the same
