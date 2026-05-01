## Why

Three minor but important UI and data refinements are required on the POS page to improve user experience and ensure receipt completeness. First, the tier selection on the "Add Customer" modal within the POS is unnecessary and clutters the UI. Second, cashiers need explicit instructions on multi-payment flows (non-cash first, cash last) directly in the checkout modal. Third, POS receipts need to show the serial numbers of products sold.

## What Changes

- Hide the tier selection dropdown from the "Add New Customer" modal within the POS.
- Add an instructional note about multi-payment best practices inside both the staged checkout and standard checkout modals.
- Update the POS receipt service and blade template to fetch and display assigned serial numbers for each product line.

## Capabilities

### New Capabilities

### Modified Capabilities

- `pos-checkout-ui`: Minor UI refinements for payment notes and customer creation.
- `pos-receipt`: Add serial numbers to the printed receipt.

## Impact

- `Modules/Pos/Resources/views/sell/modals/customer_create.blade.php`: Hide tier input.
- `Modules/Pos/Resources/views/sell/modals/checkout.blade.php` & `staged_checkout.blade.php`: Add static info message.
- `Modules/Pos/Services/PosReceiptService.php`: Map `assigned_serials` from line meta.
- `Modules/Pos/Resources/views/receipt.blade.php`: Render serial numbers if present.
