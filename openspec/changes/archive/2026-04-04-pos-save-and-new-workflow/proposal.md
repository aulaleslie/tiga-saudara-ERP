## Why

Currently, when the "Simpan dan Buka Baru" (Save and Open New) button is clicked in the POS interface, the transaction is saved as a draft and the cart is cleared, but there is no immediate visual confirmation of the transaction number or an easy way to print a receipt for the draft. Users need to see the transaction number immediately for their records and have the option to print a "pro-forma" or draft receipt without completing a payment. This improves the workflow efficiency for multi-step sales where a draft is needed before final checkout. Additionally, users have requested that the receipt font be bolder for better readability on thermal printers.

## What Changes

- Enhancement of the "Simpan dan Buka Baru" (Save and Open New) workflow in the POS Sell interface.
- Introduction of a success modal that appears after saving a draft transaction, displaying the POS TRX number.
- Addition of two action buttons in the success modal: "Lanjut" (to continue with a new cart) and "Cetak Struk" (to print a draft receipt).
- Implementation of a new backend route and logic to generate and display a receipt for a draft `PosTransaction` (without payment info).
- Modification of the receipt styling to use bolder fonts for improved readability.

## Capabilities

### New Capabilities
- `pos-draft-receipt`: Implementation of receipt generation and printing functionality for draft POS transactions (PosTransaction entities) that haven't been finalized through the checkout process.

### Modified Capabilities
- `pos-ui-enhancements`: Updating the POS Sell UI to include the new success modal and event handlers for the save-and-new workflow.
- `pos-receipt-styling`: Updating the global POS receipt template to use bolder fonts for better printer legibility.

## Impact

- **UI**: `Modules/Pos/Resources/views/sell.blade.php` will be modified to include the new modal and updated JavaScript handlers.
- **Backend Routes**: `Modules/Pos/Routes/web.php` will have a new route for draft receipts.
- **Controllers**: `Modules/Pos/Http/Controllers/PosTransactionController.php` will be updated with a `receipt` method.
- **Services**: `Modules/Pos/Services/PosReceiptService.php` will be extended to handle `PosTransaction` data.
- **Views**: `Modules/Pos/Resources/views/receipt.blade.php` will be updated with bolder CSS styling.
