## Why

The current Laporan Laba Rugi only reports against the active `session('setting_id')`, so users cannot compare or consolidate operational profit/loss across selected companies from the existing `/profit-loss-report` page. Users with report access need a flexible scope control that can include one, many, or all companies while preserving the existing operational report rows and export parity.

## What Changes

- Add a company scope selector to the existing Laporan Laba Rugi filter area.
- Allow users with `reports.access` to select multiple settings/companies to include in the report.
- Keep the default report scoped to the current active setting when no explicit multi-company selection is made.
- Treat selecting every available setting as a `Semua Perusahaan` report scope.
- Update screen and Excel export calculations to use the same selected setting IDs.
- Keep report currency as IDR for all scopes because this installation uses IDR across companies.
- Preserve the existing `/profit-loss-report` route, `reports.access` permission, operational rows, date filters, and Excel export entry point.

## Capabilities

### New Capabilities

- `profit-loss-report-setting-scope`: Defines how Laporan Laba Rugi users choose one or more companies/settings for report calculation and export.

### Modified Capabilities

- None.

## Impact

- Affected Livewire component: `app/Livewire/Reports/ProfitLossReport.php`.
- Affected Blade view: `resources/views/livewire/reports/profit-loss-report.blade.php`.
- Affected report service/value generation: `app/Services/Reports/OperationalProfitLossReportService.php`.
- Affected export: `app/Exports/ProfitLossReportExport.php`.
- Affected tests: `tests/Feature/Livewire/Reports/ProfitLossReportTest.php`.
- No database schema changes or new routes are expected.
