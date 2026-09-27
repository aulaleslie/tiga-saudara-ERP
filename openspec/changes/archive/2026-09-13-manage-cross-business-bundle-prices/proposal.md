## Why

The cross-business product price page currently manages base and conversion prices but does not expose the sale prices of the selected product's replicated bundles. Administrators must visit individual bundle edit pages, making it difficult to compare and maintain intentionally different bundle prices across businesses.

## What Changes

- Add a `Harga Paket` matrix to `/products/{product}/cross-business-prices` with one row per business and one column per distinct non-null bundle `replica_group_uuid` belonging to the routed product.
- Display and edit `bundle_sale_price` only where an actual bundle copy exists for the business and group.
- Render missing business/group combinations as read-only `Paket tidak tersedia` cells; do not treat them as zero-priced bundles or create missing bundle copies.
- Extend page-level edit, cancel, masking, validation restoration, and apply-to-all behavior to existing bundle-price cells.
- Validate bundle IDs and their persisted product, setting, and replica-group membership on the server; client-provided lineage cannot redirect an update.
- Save base, conversion, and bundle prices atomically and reject stale bundle data rather than overwriting concurrent changes.
- Record qualifying bundle price changes through the existing product price update feed.
- Keep inactive bundle groups visible with status guidance so their existing prices remain maintainable.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `cross-business-product-price-management`: Extend the existing authorized price matrix and combined atomic save to grouped bundle sale prices, including missing-copy, lineage-validation, inactive-state, and stale-data behavior.
- `cross-business-price-column-copy`: Extend apply-to-all copying to bundle replica-group columns while targeting only existing editable bundle copies.

## Impact

- Affects the Product module cross-business price controller, request validation, service, Blade view, and focused feature/masking tests.
- Reads and updates existing `product_bundles.bundle_sale_price`, grouped by the existing `replica_group_uuid`.
- Includes a forward migration on `product_price_feed_events` dropping the legacy unique constraint on `operation_uuid` in favor of a standard index so multiple feed event rows in a combined save share one operation UUID.
- Reuses `products.manage_cross_business_prices`, the current price mask/edit controls, database transaction boundary, optimistic-locking approach, and product price feed infrastructure.
- Preserves bundle composition, names, dates, activation state, setting ownership, and existing bundle edit synchronization behavior.
