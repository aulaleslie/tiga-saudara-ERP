## 1. Bundle Price Selection

- [x] 1.1 Update Sales bundle selection to load and use `product_bundles.bundle_sale_price` as the parent cart row unit price.
- [x] 1.2 Stop using legacy `product_bundles.price` as a Sales bundle add-on.
- [x] 1.3 Add stable bundled-row metadata to cart options so later recalculation paths can detect selected bundles.
- [x] 1.4 Ensure selected bundle component rows are added to cart options with non-billable price/subtotal values.

## 2. Cart Recalculation Behavior

- [x] 2.1 Update customer selection repricing so non-bundled rows still use customer tier pricing while bundled rows preserve their current parent row price.
- [x] 2.2 Update quantity changes so bundled rows multiply the current parent row price and skip cascading quantity repricing.
- [x] 2.3 Update manual parent row price edits so edited bundled row prices remain authoritative for subsequent recalculations.
- [x] 2.4 Update line discount, global discount, line tax, and tax-included recalculations to preserve bundled row unit prices.
- [x] 2.5 Update PKP/non-PKP reconciliation paths to preserve bundled row pricing and keep component totals non-billable.

## 3. Edit Hydration and Persistence

- [x] 3.1 Update Sales edit cart hydration so existing bundled rows hydrate from the parent `sale_details` price as the current editable row price.
- [x] 3.2 Normalize hydrated bundle component prices/subtotals as non-billable cart context.
- [x] 3.3 Update Sales create persistence so `sale_bundle_items` component rows do not persist billable subtotal amounts for selected bundles.
- [x] 3.4 Update Sales update persistence so recreated `sale_bundle_items` component rows remain non-billable.
- [x] 3.5 Verify `SaleNormalizer` continues deriving sale header totals from parent sale details only for bundled rows.

## 4. Sales UI

- [x] 4.1 Keep the parent bundled cart row price editable in Sales create/update.
- [x] 4.2 Hide or render bundle component item prices as read-only informational data in Sales bundle detail UI.
- [x] 4.3 Ensure bundle detail labels no longer imply legacy add-on pricing.
- [x] 4.4 Verify Sales show/print views do not present bundle component amounts as billable totals for selected bundle rows.

## 5. Tests

- [x] 5.1 Add Sales create coverage for bundle selection initializing parent row price from `bundle_sale_price`.
- [x] 5.2 Add coverage that legacy `product_bundles.price` is not added to bundled Sales row totals.
- [x] 5.3 Add coverage that manual bundled row price edits are preserved through quantity, discount, tax, and customer-change recalculations.
- [x] 5.4 Add coverage that customer tier and cascading quantity repricing still apply to non-bundled rows but not bundled rows.
- [x] 5.5 Add Sales edit coverage for hydrating existing bundled rows with parent sale detail price and non-billable component rows.
- [x] 5.6 Add persistence coverage that `sale_bundle_items` component price/subtotal values do not accumulate into sale totals.
- [x] 5.7 Run targeted Sales cart, Sales persistence, Product Bundle pricing, and existing bundle dispatch regression tests.
