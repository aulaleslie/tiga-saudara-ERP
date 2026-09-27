# Tasks

## 1. Product Edit Behavior

- [x] 1.1 Update the unit-configuration view so `product_stock_alert` remains enabled for a stock-managed product with existing stock while the existing-stock lock continues to protect structural controls; verify with focused rendered-component or feature assertions.
- [x] 1.2 Add adjacent help text explaining that the threshold applies across all businesses and locations; verify the product edit response contains the global-scope guidance.

## 2. Focused Regression Coverage

- [x] 2.1 Add a focused product-edit test that submits a new threshold for a product with existing stock and verifies `products.product_stock_alert` changes without creating a setting-specific threshold.
- [x] 2.2 Cover threshold validation and scope boundaries by verifying invalid values are rejected, structural fields remain protected, and any submitted price change remains scoped to the active business.
- [x] 2.3 Run only the directly relevant Product test file(s) or filtered tests and confirm they pass; do not run the full test suite for this localized change.
