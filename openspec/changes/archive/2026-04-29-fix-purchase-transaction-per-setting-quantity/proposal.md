## Why

When a purchase receiving is approved, the transaction log records `previous_quantity`, `after_quantity`, and `current_quantity` using the global `product.product_quantity` (sum across ALL settings). Since the product show page filters transactions per-setting, users see incorrect stock numbers — e.g., "Jumlah Saat Ini: 90" when their setting only owns 60 units. This causes confusion and makes the stock mutation history unreliable for any setting that shares products with other settings.

## What Changes

- Fix `PurchaseController::approveReceiving()` to compute `previous_quantity`, `after_quantity`, and `current_quantity` as the **per-setting sum** (sum of `product_stocks.quantity` across all locations belonging to the purchase's setting) instead of using the global `product.product_quantity`.
- The `previous_quantity_at_location` and `after_quantity_at_location` fields remain unchanged — they already correctly use `productStock->quantity` (per-location).
- The global `product.product_quantity` increment stays unchanged — it is the correct aggregate counter.
- No schema changes required — existing columns are sufficient.

## Capabilities

### New Capabilities
- `purchase-receiving-per-setting-quantity`: Fix the transaction quantity snapshot in purchase receiving approval to record per-setting stock totals instead of global totals.

### Modified Capabilities
_(none — `purchase-creation` spec covers form/tax behavior, not the receiving approval transaction log)_

## Impact

- **Code**: `Modules/Purchase/Http/Controllers/PurchaseController.php` — `approveReceiving()` method only.
- **Data**: Future transaction records will have correct per-setting quantities. Existing records with global quantities are historical artifacts and not affected.
- **APIs**: None.
- **Dependencies**: Uses existing `Location` and `ProductStock` models (no new dependencies).
