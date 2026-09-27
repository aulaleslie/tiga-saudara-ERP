## Why

Users need a Neraca report even though the ERP does not yet have a complete accounting journal implementation. The report should provide a practical, management-facing balance sheet view derived from existing operational transactions so owners can review assets, liabilities, and equity without waiting for full accounting infrastructure.

## What Changes

- Add an operational Neraca report under Reports > Sekilas bisnis.
- Generate the report from related transactions instead of chart-of-account journal balances.
- Omit the account number column because operational rows are derived reporting buckets, not accounting accounts.
- Provide an as-of date filter, current setting scope, and IDR/company currency display.
- Include asset, liability, and equity sections with balanced totals.
- Add XLSX export for the same report data.
- Add a report note explaining that the report is calculated from operational transactions and does not yet use accounting journals.

## Capabilities

### New Capabilities
- `operational-balance-sheet-report`: Provides a Neraca report calculated from operational sales, purchases, returns, payments, expenses, inventory, and derived equity data.

### Modified Capabilities

None.

## Impact

- Reports module landing card and routes.
- New report controller/view, Livewire report component, report service/value objects, and export class.
- Operational transaction queries across sales, purchases, sales returns, purchase returns, expenses, payments, inventory valuation, settings, and currencies.
- Focused feature and service tests for formula correctness, authorization, route visibility, and export parity.
