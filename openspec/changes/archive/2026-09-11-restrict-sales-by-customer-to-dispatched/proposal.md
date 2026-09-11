## Why

Penjualan Per Customer currently includes sale details regardless of the Sale lifecycle status, allowing draft, unapproved, rejected, and partially dispatched transactions to overstate customer sales. The report should recognize revenue rows only from transactions whose fulfillment has reached the authoritative fully dispatched state.

## What Changes

- Restrict Penjualan Per Customer results to non-archived Sales whose exact status is `DISPATCHED`.
- Exclude Sales in every other lifecycle status, including `DISPATCHED PARTIALLY`, `RETURNED PARTIALLY`, and `RETURNED`.
- Apply the restriction consistently to screen rows, customer totals, sorting, pagination, running totals, and XLSX/CSV exports through the shared report query.
- Add focused automated coverage for included and excluded statuses; browser behavior will be verified manually rather than through browser automation.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `sales-by-customer-report`: Require report data and all derived totals/exports to include only Sales in the exact `DISPATCHED` status.

## Impact

- Affects the shared Penjualan Per Customer query in `app/Services/Reports/SaleByCustomerReportQueryService.php`.
- Affects existing focused feature tests in `Modules/Reports/Tests/Feature/SaleByCustomerReportTest.php`.
- Changes report visibility only; no routes, permissions, database schema, dependencies, transaction lifecycle logic, or historical records are changed.
