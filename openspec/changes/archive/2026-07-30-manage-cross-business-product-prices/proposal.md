## Why

Product pricing is stored per business, but authorized users can currently view and edit only the active business's price values through the standard product screen. Maintaining a product's commercial prices across all businesses is therefore slow and risks unintentionally replacing the inventory-derived average purchase price.

## What Changes

- Add a permission-protected cross-business product price page, entered from an authorized product-list action.
- Show one row for every business/setting and provide a safe view mode, page-level edit mode, back navigation, cancel, and one atomic save for all displayed rows.
- Allow editing sales price, tier 1 price, tier 2 price, and purchase price (the `last_purchase_price` value); always show average purchase price as read-only.
- Default missing price values to zero and create the missing per-business price row on a successful bulk save.
- Preserve existing tax metadata and average purchase price while using lightweight optimistic concurrency checks and disabling Save while submission is in progress.
- Correct standard product creation and editing so manually entered purchase price updates only `last_purchase_price`; average purchase price remains zero for a new row and is calculated only by purchase processing.

## Capabilities

### New Capabilities

- `cross-business-product-price-management`: Authorized users can view and atomically maintain a selected product's commercial prices across every business.
- `manual-product-purchase-price-handling`: Manual product create/edit price inputs update last purchase price without recalculating the inventory-derived average purchase price.

### Modified Capabilities

<!-- None. Existing purchase-price normalization and price-list requirements remain unchanged. -->

## Impact

- Product list action partial, Product module routes/controller or Livewire page, price views, and centralized permission configuration/seeding.
- `ProductPrice` read/write behavior for `product_prices`, including existing `updated_at` concurrency metadata; no schema change is expected.
- Product creation service and standard product update flow.
- Focused Laravel feature tests for authorization, pricing defaults/upserts, atomicity, stale-save handling, and manual-price/average-price separation.
