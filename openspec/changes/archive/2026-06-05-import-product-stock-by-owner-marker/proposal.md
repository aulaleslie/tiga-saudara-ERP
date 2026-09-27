## Why

Warehouse stock quantity files currently cannot be imported as an owner-aware product stock snapshot. Users need a controlled way to upload the product list CSV, normalize product-name owner markers, create missing products, and overwrite each owner location's stock quantity including zero and negative values.

## What Changes

- Add a product stock quantity import flow for CSV files shaped like `Product Code, Product Name, Unassigned, Total Quantity, Product Unit`.
- Normalize product-name markers before matching or creating products:
  - leading `*` routes stock to CV TIGA NUSA COMPUTER and is removed from the stored/matched product name.
  - trailing `TP` routes stock to CV TOP IT INTERNUSA and is removed from the stored/matched product name.
  - no marker routes stock to PERDANA.
- Use the first configured location for the resolved owner setting as the stock target.
- Create missing products from the clean product name, optional product code, and product unit.
- Overwrite the target product/location stock quantity from `Total Quantity`, including `0` and negative quantities.
- Preserve import batch and row visibility so users can inspect row status, errors, raw payload, product mapping, and stock effects.
- Record stock mutation/audit entries for overwritten quantities.

## Capabilities

### New Capabilities
- `product-stock-owner-marker-import`: Imports product stock quantity snapshots by owner marker, creating missing products and overwriting owner-location stock.

### Modified Capabilities

## Impact

- Affects `Modules/Product` upload/import controllers, product import jobs/services, product import batch/row models, import monitoring views, product stock updates, and stock transaction audit records.
- Reuses marker behavior from sales import and product-name normalization patterns from existing product import.
- No external dependencies are expected.
