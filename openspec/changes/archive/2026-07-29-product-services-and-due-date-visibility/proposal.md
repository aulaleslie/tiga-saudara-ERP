## Why

Product maintenance currently rejects valid zero-value purchase and sale prices, and disabling stock management can fail validation before its intended conversion cleanup happens. POS also treats every sellable product as inventory-backed, preventing service products from being sold. Finally, existing sales due dates are not visible in two payment/detail views where staff need them.

## What Changes

- Permit optional purchase and sale prices of zero in the standard Product Create and Edit flows while continuing to reject negative values.
- Allow a zero-stock product to disable stock management and serial tracking; disabling stock management removes its unit conversions, conversion prices, and conversion barcode identities without stale conversion validation errors.
- Allow non-stock-managed products to be searched, scanned, and sold in POS as services without inventory availability, serial, or stock-mutation requirements; retain all existing controls for stock-managed products.
- Display the existing sale due date in Global Sales Payment history and the read-only POS Sale Detail modal.

## Capabilities

### New Capabilities

- `product-catalog-maintenance`: Standard product create/edit price validation and stock-management transition behavior.

### Modified Capabilities

- `pos-product-search-stock-visibility`: POS product discovery and selection rules now include non-stock-managed service products.
- `pos-cart-management`: POS cart quantity and add-to-cart behavior distinguishes services from inventory-managed products.
- `global-sales-multi-payment`: Global payment history shows the related sale's existing due date.
- `pos-sale-document-linkage`: Read-only POS Sale Detail modal shows the linked sale's existing due date.

## Impact

- Affected Product request validation, edit form configuration, conversion cleanup, and product price persistence.
- Affected POS search, scan resolution, cart validation, result-card rendering, and focused POS regression tests.
- Affected Global Sales Payment data table/query and POS read-only sale-detail Blade view.
- No database migration, public API, payment-term calculation, or due-date persistence behavior is required.
