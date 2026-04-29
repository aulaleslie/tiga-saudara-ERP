## 1. Core Fix

- [x] 1.1 In `PurchaseController::approveReceiving()`, pre-fetch setting location IDs (`Location::where('setting_id', $purchase->setting_id)->pluck('id')`) outside the detail loop
- [x] 1.2 Inside the detail loop, compute `$previous_quantity_for_setting` as `ProductStock::where('product_id', $product->id)->whereIn('location_id', $settingLocationIds)->sum('quantity')` before the stock increment
- [x] 1.3 Compute `$after_quantity_for_setting = $previous_quantity_for_setting + $receivedQuantity` after the stock increment
- [x] 1.4 Update `Transaction::create()` to use `$previous_quantity_for_setting` for `previous_quantity`, `$after_quantity_for_setting` for `after_quantity`, and `$after_quantity_for_setting` for `current_quantity`

## 2. Verification

- [x] 2.1 Write a feature test: approve a purchase receiving where the product has stock in multiple settings, assert that the transaction's `previous_quantity` and `after_quantity` reflect only the purchase's setting stock (not global)
- [x] 2.2 Write a feature test: approve a purchase receiving for a product with zero stock in the purchase's setting (but stock in other settings), assert `previous_quantity = 0`
- [x] 2.3 Verify that `previous_quantity_at_location` and `after_quantity_at_location` remain unchanged (per-location values from `productStock->quantity`)
- [x] 2.4 Verify that `product.product_quantity` (global counter) still increments correctly
