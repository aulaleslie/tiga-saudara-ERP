## 1. UI Refinements

- [x] 1.1 Hide the customer tier field in the POS customer creation modal (`Modules/Pos/Resources/views/sell/modals/customer_create.blade.php`) using `d-none`.
- [x] 1.2 Add the multi-payment instructional note to the standard checkout modal (`Modules/Pos/Resources/views/sell/modals/checkout.blade.php`).
- [x] 1.3 Add the multi-payment instructional note to the staged checkout modal (`Modules/Pos/Resources/views/sell/modals/staged_checkout.blade.php`).

## 2. Receipt Enhancements

- [x] 2.1 Update `PosReceiptService::getReceiptData` to include `assigned_serials` in the line item array.
- [x] 2.2 Update `PosReceiptService::getTransactionReceiptData` to include `assigned_serials` in the line item array.
- [x] 2.3 Update the POS receipt view (`Modules/Pos/Resources/views/receipt.blade.php`) to render the serial numbers for each line item.
