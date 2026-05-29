## Why

The current purchase report is available as a flat `Laporan Pembelian` entry and does not match the desired report navigation or sample-inspired filter layout. Users need a clearer `Laporan -> Pembelian -> Daftar Pembelian` report page that keeps the trusted one-row-per-purchase result model while organizing filters into a compact top bar and right-side advanced filter drawer.

## What Changes

- Replace the flat `Laporan Pembelian` sidebar entry with nested navigation: `Laporan -> Pembelian -> Daftar Pembelian`.
- Keep the existing `/reports/purchase-report` route and reuse the existing `purchaseReports.access` permission.
- Rename the page title and breadcrumb to `Daftar Pembelian`.
- Change the report default date range to today only.
- Rework the filter UI into:
  - top filter bar with start date, end date, period preset, `Filter`, `Filter lainnya`, and a sample-like `Ekspor` dropdown shell;
  - right-side `Filter lainnya` drawer containing transaction type, date basis, supplier multi-select, delivery status, and payment status filters.
- Make period presets update pending date filter state, but run the query only when the user clicks `Filter`.
- Keep result rows at purchase-header level: one row per purchase invoice/header.
- Keep visual styling aligned with existing CoreUI/Bootstrap ERP conventions rather than copying external CSS.
- Provide a non-functional export dropdown shell for v1 with disabled options; no export generation is included in this change.

## Capabilities

### New Capabilities
- `purchase-list-report`: Defines the purchase listing report navigation, permissions, filter layout, drawer behavior, status filters, result row scope, and v1 export-shell behavior.

### Modified Capabilities
- None.

## Impact

- Affected UI:
  - `resources/views/layouts/menu.blade.php`
  - `Modules/Reports/Resources/views/purchase-report/index.blade.php`
  - `resources/views/livewire/reports/purchase-report.blade.php`
- Affected Livewire/backend report flow:
  - `app/Livewire/Reports/PurchaseReport.php`
  - `app/Services/Reports/PurchaseReportFilterData.php`
  - `app/Services/Reports/PurchaseReportValidator.php`
  - `app/Services/Reports/PurchaseReportQueryService.php`
- Affected tests:
  - purchase report Livewire/feature tests for navigation visibility, default dates, filter state, drawer filters, and result filtering.
- No database schema changes are expected.
- No route URL change is expected.
- No new permission is expected.
