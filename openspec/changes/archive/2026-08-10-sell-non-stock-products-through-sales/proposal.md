## Why

Non-stock-managed products, such as repair services, are valid saleable offerings but cannot currently be sold through the standard Sales workflow. Product search excludes them and downstream Sales validation and dispatch treat every line as inventory, forcing users to create artificial stock or avoid Sales.

## What Changes

- Allow active products marked `is_sold` to be selected and priced in standard Sales even when `stock_managed` is false.
- Preserve normal Sales document, pricing, discount, tax, customer, payment, invoice, reporting, and zero-cost-snapshot behavior for non-stock lines.
- Apply stock availability validation only to stock-managed parent products and stock-managed bundle components.
- Exclude non-stock lines and components from Sales dispatch demand, location/serial selection, stock mutation, and inventory transactions.
- Base Sales dispatch-progress status solely on stock-managed fulfillment demand so a mixed sale can complete once all physical goods are dispatched.

## Capabilities

### New Capabilities

- `sales-non-stock-product-lines`: Standard Sales can sell non-stock-managed products without inventory fulfillment side effects.

### Modified Capabilities

- `standard-sale-document-lines`: Dispatch demand and completion semantics change to include only inventory-fulfilled Sales lines.

## Impact

- Affected UI and server paths: Sales product search, Sales cart, quick product add, sale creation/update validation, dispatch aggregation/validation/approval, and related feature tests.
- Existing Product, Sale, SaleDetails, bundle, ProductStock, dispatch, tax, pricing, payment, invoice, reporting, and cost-snapshot data structures remain in use; no new application entity or migration is expected.
- Explicitly out of scope: repair intake/work orders, technician assignment, service completion tracking, POS behavior, and Sales Return behavior.
