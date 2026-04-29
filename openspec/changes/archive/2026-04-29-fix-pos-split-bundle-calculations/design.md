## Context

In the current ERP system, POS split transactions allow multiple owners (settings) to fulfill a single cart. For bundle products, this means one owner might provide the parent product (residual stock) while others provide specific bundle components.

Current issues:
1. `PosCheckoutSplitPlannerService` defaults parent row quantity to the full cart quantity even if the group doesn't own any parent stock.
2. `InlinePosCheckoutPostingAdapter` hardcodes bundle item prices to zero and ignores their tax contributions.

## Goals / Non-Goals

**Goals:**
- Reflect accurate ownership of both parent products and bundle items in split sales.
- Set parent row quantity and price to 0 when an owner only fulfills bundle components.
- Persist actual prices and subtotals for `SaleBundleItem` records.
- Ensure tax totals for split sales include contributions from bundle items.

**Non-Goals:**
- Implementing serial number support for bundle items.
- Changing the underlying bundle decomposition logic.

## Decisions

### 1. Zero-Quantity Parent Rows
When a split group has `parent_qty == 0` but owns bundle components:
- `qty` will be set to `0`.
- `unit_price` will be set to `0`.
- `line_subtotal` will remain the sum of the allocated bundle items.
- `stock_managed` and `serial_number_required` will be set to `false` (to avoid inventory movement for the parent).

**Rationale**: This prevents the "Total Quantity" of the parent product from being over-counted across multiple split sales while maintaining the parent-child relationship in the database.

### 2. Allocation-Based Bundle Item Persistence
`InlinePosCheckoutPostingAdapter` will sum up the `allocated_minor` and `allocated_qty` from the split group's allocations for each bundle item.
- `SaleBundleItem.price` = `sum(allocated_minor) / sum(allocated_qty)`
- `SaleBundleItem.sub_total` = `sum(allocated_minor)`

**Rationale**: This ensures that each owner's sale accurately reflects the revenue they generated from bundle components.

### 3. Trusting Planner-Calculated Tax
The adapter will use the `line_tax_total` pre-calculated by `PosCheckoutSplitPlannerService` instead of attempting to recalculate it from parent allocations alone.

**Rationale**: The planner has access to the full allocation context (both parent and child) during the split phase, making its tax calculation more reliable than the adapter's partial view.

## Risks / Trade-offs

- **Zero Quantity Handling**: Some reporting or third-party integrations might assume `sale_details.quantity` is always > 0. However, since these rows are marked as `stock_managed = false`, they should not interfere with inventory reconciliation.
- **Floating Point Precision**: When calculating `unit_price` from minor units (cents), we must use `round()` to avoid precision issues in the DB decimal columns.
