## Why

Currently, when making a global payment to a supplier, all of the supplier's unpaid invoices are loaded onto a single page. If a supplier has a large number of unpaid invoices, the page becomes long and cumbersome to navigate. Additionally, it is difficult to identify specific invoices because the supplier's external purchase number (`supplier_purchase_number`) is missing from the list. This change introduces client-side pagination to improve usability and adds the external supplier number for easier cross-referencing.

## What Changes

- Add the `supplier_purchase_number` (External Number) column to the Global Purchase Payment invoice list.
- Introduce client-side pagination using DataTables for the invoice list.
- Configure the standard ERP rows-per-page options (`[10, 25, 50, 100, "All"]`) for the pagination.
- Modify the form submission logic to ensure all allocations across paginated views are correctly serialized and submitted.

## Capabilities

### New Capabilities
- `global-payment-improvements`: Covers adding the external supplier number and client-side pagination with state preservation to the global payment view.

### Modified Capabilities


## Impact

- **Views**: `Modules/Purchase/Resources/views/payments/global-create.blade.php` will be updated to include the new column, DataTables initialization, and modified form submission script.
- **Dependencies**: Leverages existing DataTables JS library. No new dependencies required.
