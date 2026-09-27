## Why

POS split transactions for bundle products currently result in inaccurate sale records when a split group owns bundle components but not the parent product. In these cases:
- The parent product row incorrectly shows the full cart quantity instead of 0.
- The parent product row shows a "fake" price (total value of components divided by full quantity).
- Bundle item rows (`sale_bundle_items`) are persisted with 0 price and 0 subtotal, making them appear as "free" items in the sales detail view.
- Tax calculations for these rows are often zero because the logic only considers parent product allocations.

This change ensures that split sales accurately reflect what each owner is providing, with zeroed-out parent rows (where applicable) and correctly priced bundle items.

## What Changes

- **Split Planning**: `PosCheckoutSplitPlannerService` will be updated to set `qty: 0` and `unit_price: 0` for parent product rows in split groups that only fulfill bundle components.
- **Sale Posting**: `InlinePosCheckoutPostingAdapter` will be updated to:
    - Allow zero-quantity sale detail rows in its validation.
    - Persist actual prices and subtotals for `SaleBundleItem` records based on their allocations.
    - Use pre-calculated tax totals from the planner instead of recalculating them from incomplete allocation data.
- **UI Consistency**: The changes will ensure that the "Sales Show" view (and receipts) display bundle item prices and subtotals correctly.

## Capabilities

### Modified Capabilities
- `pos-checkout-finalize-integration`: Update the split posting logic to accurately handle bundle component ownership and pricing.
- `pos-transaction-detail-bundle-display`: Ensure bundle items show their allocated prices and subtotals in transaction details.

## Impact

- `Modules/Pos/Services/PosCheckoutSplitPlannerService.php`
- `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`
- Sale records and receipts involving split bundle transactions.
