## Why

Currently, POS receipts and reprint receipts fall back to `contact_name` or `customer_name` using PHP's null coalescing operator (`??`). However, if `contact_name` is an empty string (`""`) in the database instead of `null`, the fallback fails, resulting in a blank space where the customer's name should be. Furthermore, for B2B customers, showing only one field drops valuable context (e.g., hiding the person's name when the business name is present, or vice versa).

## What Changes

- **Add Smart Accessor**: Add a `getDisplayNameAttribute()` accessor to the `Modules\People\Entities\Customer` model to reliably handle empty strings and intelligently combine `contact_name` and `company_name` (or `customer_name`) using `"Contact - Company"` format when both are present.
- **Update Receipt Service**: Refactor `Modules\Pos\Services\PosReceiptService` to use `$customer->display_name` instead of the fragile `??` chain for both `getReceiptData` and `getTransactionReceiptData`.

## Capabilities

### New Capabilities

- None

### Modified Capabilities

- `pos-receipt`: Ensure customer name on receipts robustly handles empty strings and combines contact/company names if both exist.
- `pos-transaction-reprint`: Reprint receipts should use the same robust customer display name logic.

## Impact

- `Modules\People\Entities\Customer` (New Accessor added)
- `Modules\Pos\Services\PosReceiptService` (Refactored to use the new accessor)
- POS Printed Receipts and Reprint Receipts (Visually updated to show "Contact - Company" when both are present instead of just one field, and fixed to not be blank on empty strings).
