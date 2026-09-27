## Why

Product Bundle configuration now stores `bundle_sale_price` as the intended final selling price for a selected sales bundle, but the Sales create/update cart still uses legacy bundle add-on pricing and can reprice bundled rows through customer tier or cascading quantity rules. This makes bundled sales totals diverge from the configured `Harga Jual Paket` and risks treating informational bundle item prices as billable amounts.

## What Changes

- Sales create/update bundled cart rows will initialize the parent row price from `product_bundles.bundle_sale_price`.
- The parent bundled row price will remain manually editable after bundle selection.
- Bundled rows will skip customer tier repricing and cascading quantity repricing while the bundle remains selected.
- Sales will ignore legacy `product_bundles.price` for bundled row totals.
- Sales will keep bundle component item prices informational only; component prices will be hidden or read-only and will not add to cart, sale detail, or sale total amounts.
- Quantity, tax, discount, customer-change, and edit hydration recalculations will preserve the bundled row's current parent row price instead of replacing it from tier/cascade pricing.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `sale-cart-pricing`: Defines Sales create/update pricing behavior for bundled cart rows, including `bundle_sale_price` initialization, manual parent price overrides, tier/cascade repricing bypass, and informational-only bundle component prices.

## Impact

- Sales Livewire cart: bundle selection, customer selection repricing, quantity updates, tax toggles, discounts, manual price edits, and edit-cart hydration.
- Sales persistence: `sale_details` totals remain authoritative for billable amounts; `sale_bundle_items` component price/subtotal must not accumulate into sale totals for selected bundles.
- Product Bundle data: reads the existing `product_bundles.bundle_sale_price` and `product_bundle_items.informational_item_price` fields without changing Product Bundle CRUD persistence.
- Legacy compatibility: stops using `product_bundles.price` in standard Sales bundle runtime pricing; POS behavior is not changed by this proposal.
- Tests: add Sales create/update coverage for bundled price initialization, manual override preservation, tier/cascade bypass, and non-billable bundle components.
