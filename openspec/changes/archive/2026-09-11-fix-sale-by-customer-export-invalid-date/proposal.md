## Why

Exporting the Penjualan Per Customer report can fail with a Carbon `InvalidFormatException` when a manually joined sale remains in the report query but its archive-scoped Eloquent relationship resolves to `null`, causing the exporter to parse the `-` placeholder as a date. The report must consistently exclude archived sales and must not attempt to parse invalid placeholder dates.

## What Changes

- Align the report query with the `Sale` model's archive scope so archived sales and their detail rows are excluded from screen and export results.
- Make exported date resolution use the effective reporting date already selected by the report query instead of depending solely on the eager-loaded sale relationship.
- Format export dates defensively so a missing or invalid source date cannot crash XLSX or CSV generation.
- Add focused regression coverage for active effective dates and archived-sale exclusion; browser export confirmation remains a human verification step.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `sales-by-customer-report`: Require Penjualan Per Customer results and exports to exclude archived sales and generate exports without invalid-date parsing failures.

## Impact

- Affected query and mapping code: `app/Services/Reports/SaleByCustomerReportQueryService.php`.
- Affected export code: `app/Exports/SaleByCustomerReportExport.php`.
- Focused report/export tests under `tests/Feature/Livewire/Reports/` or the nearest existing report test location.
- No route, database schema, API, dependency, or permission changes.
