## Why

When creating a new product accurately, the "Low Quantity Alert" (stock threshold) provided in the management form is not being persisted to the database. It is currently always reset to 0 during the creation process, leading to loss of configured data and preventing low-stock notifications from triggering correctly for newly created items.

## What Changes

- Update the `ProductCreator` service to ensure the `product_stock_alert` value from the validated form data is preserved during the creation of a `Product` entity.
- Remove `product_stock_alert` from the list of fields that are forcibly zeroed out (cleared) during the product creation lifecycle.
- Maintain a safe fallback to 0 if the value is missing from the request to satisfy database integrity constraints.

## Capabilities

### New Capabilities
- `product-creation`: Requirements for persisting product core data during creation.

### Modified Capabilities
- None

## Impact

- **Modules/Product/Services/ProductCreator.php**: Removal of the hardcoded default for `product_stock_alert` in the `create` method.
