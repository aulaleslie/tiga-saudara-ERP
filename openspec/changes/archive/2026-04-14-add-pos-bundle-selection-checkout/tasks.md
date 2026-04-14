## 1. POS bundle selection flow

- [x] 1.1 Add a POS endpoint/service flow that returns available bundles for a selected bundle-parent product.
- [x] 1.2 Extend the POS sell shell in `Modules/Pos/Resources/views/sell.blade.php` with a bundle selection modal that lets the cashier select a bundle or continue without one.
- [x] 1.3 Extend POS cart line creation requests to accept bundle selection state and validate that the selected bundle belongs to the selected parent product.

## 2. Bundle-aware POS cart snapshot

- [x] 2.1 Extend `PosCartService` cart line storage to persist `bundle_mode`, `bundle_id`, `bundle_name`, `bundle_price`, and normalized `bundle_items` metadata on a parent line.
- [x] 2.2 Update POS cart merge-key behavior so plain parent lines, skipped-bundle lines, and different selected bundles stay as distinct cart lines.
- [x] 2.3 Update POS cart snapshot rendering and totals logic so bundled parent lines remain single visible lines while preserving bundle metadata needed for checkout.

## 3. Bundle-aware POS checkout posting

- [x] 3.1 Extend POS checkout posting to persist selected bundle composition alongside the parent sale detail.
- [x] 3.2 Build checkout stock validation so the parent product and each bundled child product are evaluated independently based on each product's `stock_managed` flag.
- [x] 3.3 Extend dispatch and inventory posting so stock deductions are created only for the parent and bundled child products whose `stock_managed` flag is true.

## 4. Verification

- [ ] 4.1 Add or update feature tests for bundle-parent selection, skipped bundle selection, and bundle-aware cart snapshot behavior in POS.
- [ ] 4.2 Add or update checkout integration tests covering parent and bundled child stock deduction branches for `stock_managed = true` and `stock_managed = false`.
- [ ] 4.3 Verify that normal POS product add and checkout behavior remains unchanged for non-bundle products.
