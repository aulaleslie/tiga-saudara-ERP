## Why

Sales orders currently fail at creation when the requested aggregate product stock is unavailable, even though inventory is selected, validated, and deducted later through the dispatch workflow. This prevents recording valid customer demand and contradicts the intended policy that stock availability is a fulfillment concern, not a sales-order entry constraint.

## What Changes

- Allow standard Sales create and editable Sales update workflows to save stock-managed products and bundle components regardless of current aggregate product quantity.
- Remove the Sales-service stock preflight that blocks sale creation before the document and lines are persisted.
- Keep dispatch as the only fulfillment-stock gate: dispatch submission continues to validate the selected location, serial availability, requested quantity, and remaining sale quantity.
- Preserve the locked stock recheck at dispatch approval before inventory is deducted, so concurrent inventory changes cannot result in negative stock.
- Replace tests that expect Sales creation to fail for unavailable stock with coverage that permits the order and rejects an unfulfillable dispatch.

## Capabilities

### New Capabilities

- `sales-dispatch-stock-gating`: Defines the boundary between recording a Sales order and validating available inventory for its dispatch.

### Modified Capabilities

- None.

## Impact

- Affected code: `Modules/Sale/Services/SaleService`, standard Sales create and edit callers, and Sales/dispatch feature tests.
- Affected behavior: Sales users can record backorders or demand for zero-stock items; warehouse/dispatch users receive stock errors only when attempting fulfillment.
- No schema, route, permission, API, POS checkout, or inventory-deduction behavior changes are required.
