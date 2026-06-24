## Why

Terminal Harga currently exposes all configured price tiers for each product, which can show more pricing information than the operator needs and does not answer the customer-specific price question directly. The screen should default to the normal non-tier price, then show the appropriate customer-tier price only after a global customer is selected.

## What Changes

- Add a global customer search/select control to Terminal Harga.
- Keep product pricing scoped to the active outlet's `product_prices.setting_id`.
- Ignore `customers.setting_id` for Terminal Harga customer lookup and tier resolution, even when it has a value.
- Display only one contextual product price per product card:
  - no selected customer or customer without a recognized tier shows `sale_price`;
  - `WHOLESALER` customer shows `tier_1_price` when positive, otherwise `sale_price`;
  - `RESELLER` customer shows `tier_2_price` when positive, otherwise `sale_price`.
- Allow clearing the selected customer and reverting displayed prices to normal non-tier prices.
- Preserve existing product search, pagination, currency formatting, and active-setting product price lookup behavior.

## Capabilities

### New Capabilities
- `terminal-harga-customer-tier-pricing`: Defines Terminal Harga product price display and global customer-tier selection behavior.

### Modified Capabilities
- None.

## Impact

- `app/Livewire/PricePoint/Browser.php`: add selected customer state, global customer search data, tier resolution, and contextual price selection.
- `resources/views/livewire/price-point/browser.blade.php`: add customer search/dropdown UI and change product cards to display one contextual price instead of all tiers.
- Tests: add focused Livewire/feature coverage for default non-tier pricing, global customer selection across differing customer `setting_id` values, tier fallback, customer clearing, and search/pagination preservation.
- No database schema changes are expected.
