# Design

## Context

The `PurchaseByProductReportQueryService::build()` query (see [source](file:///home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/app/Services/Reports/PurchaseByProductReportQueryService.php)) uses:

```php
$query->groupBy(
    'purchase_details.product_id',
    'purchase_details.product_code',
    'purchase_details.product_name',
    'unit_name'  // ← alias collides with purchase_details.unit_name column
);
```

MySQL 8.4 resolves the bare `unit_name` in GROUP BY to `purchase_details.unit_name` (the table column) rather than the SELECT alias `COALESCE(units.short_name, ...)`. Since `purchase_details.unit_name` is `NULL` on older rows and `'UNIT'` on newer rows, the same product splits into two groups that display identically.

See proposal.md for full motivation.

## Goals / Non-Goals

**Goals:**
- Fix the GROUP BY to use `purchase_details.product_id` only
- Derive display values (product_code, product_name, unit) from the `products`/`units` master tables via aggregation-safe expressions (e.g. `MAX()` or `ANY_VALUE()`)

**Non-Goals:**
- Backfilling `purchase_details.unit_name` NULLs (historical data stays as-is)
- Changing any other report query (sale-by-product, purchase-by-supplier, etc.)
- Adding return-quantity columns (deferred)

## Decisions

### 1. GROUP BY `purchase_details.product_id` only

**Rationale**: `product_id` is the true grouping identity. Snapshot columns (`product_code`, `product_name`) on `purchase_details` were copied at purchase creation time and can drift from the master. Since the SELECT already uses `COALESCE(products.*, purchase_details.*, '')`, the master table is the source of truth for display.

**Alternative considered**: Rename the alias from `unit_name` to `display_unit_name` to avoid the MySQL column collision. Rejected because it only fixes the unit issue — snapshot columns in GROUP BY would still produce duplicates if `purchase_details.product_name` ever changes, and grouping by them is architecturally wrong for this report.

### 2. Wrap master-table columns in `MAX()` for MySQL strict-mode compliance

Since the SELECT references `products.product_code`, `products.product_name`, and `units.short_name` which are functionally dependent on `product_id` but MySQL's `ONLY_FULL_GROUP_BY` mode won't infer that through a LEFT JOIN, we wrap them:

```php
DB::raw("MAX(COALESCE(products.product_code, purchase_details.product_code, '')) as product_code"),
DB::raw("MAX(COALESCE(products.product_name, purchase_details.product_name, '')) as product_name"),
DB::raw("MAX(COALESCE(units.short_name, base_units.short_name, products.product_unit, '-')) as unit_name"),
```

`MAX()` is safe here because all rows for the same `product_id` resolve to the same master product/unit values.

**Alternative considered**: `ANY_VALUE()` — MySQL-specific and less portable. `MAX()` works identically for these single-valued groups and is ANSI-standard.

## Risks / Trade-offs

- **[Risk] Products with NULL `product_id`** → If any `purchase_details` rows have `product_id = NULL`, they would group together into one row. Verified in the database: no such rows exist for eligible purchases. Mitigation: the existing `whereIn('purchase_details.product_id', ...)` filter and the LEFT JOIN to `products` already handle this gracefully.
- **[Risk] Sort stability** → The secondary sort `purchase_details.product_id ASC` in `applySort()` remains valid and ensures deterministic order.
