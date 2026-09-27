## Why

The Purchase by Supplier report can select detail rows from an archived purchase through its SQL join while Eloquent hides the same purchase through the archival global scope. This mismatch causes the report to crash when it dereferences the missing relationship and can also allow archived values into counts, totals, pagination, or exports.

## What Changes

- Exclude archived purchases at the shared Purchase by Supplier report query boundary.
- Keep on-screen rows, result counts, pagination, running totals, grand totals, and Excel/CSV exports based on the same non-archived dataset.
- Add focused regression coverage proving that archived purchases inside the selected business and date range are omitted without affecting matching active purchases.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `purchase-by-supplier-report`: Define that normal Purchase by Supplier results and their derived values include only non-archived purchases.

## Impact

- Affects `PurchaseBySupplierReportQueryService`, which supplies the Livewire report, filter snapshot count, pagination/totals, and exports.
- Adds focused report regression coverage in the existing Purchase by Supplier feature test area.
- No database migration, API change, new dependency, or breaking change is required.
