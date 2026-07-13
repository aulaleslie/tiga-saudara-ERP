## 1. Customer Model Update

- [x] 1.1 Add `getDisplayNameAttribute` accessor to `Modules\People\Entities\Customer` model. Ensure it uses `filled()` to safely ignore empty strings, formats the output as "Contact Name - Company Name" when both are present, and falls back gracefully to whichever is present (or `customer_name`).

## 2. Receipt Service Update

- [x] 2.1 Refactor `getReceiptData` in `Modules\Pos\Services\PosReceiptService` to resolve the customer name using `$customer->display_name` (from the checkout or the sale customer relation).
- [x] 2.2 Refactor `getTransactionReceiptData` in `Modules\Pos\Services\PosReceiptService` to resolve the customer name using `$transaction->customer?->display_name`.

## 3. Verification

- [x] 3.1 Verify POS receipt endpoint properly displays the new robust customer name.
- [x] 3.2 Verify POS reprint receipt endpoint properly displays the new robust customer name without trailing dashes or empty spaces.
