## 1. Test Coverage

- [x] 1.1 Add service-level tests proving default/current setting scope includes only the active setting and excludes other settings.
- [x] 1.2 Add service-level tests proving selected multiple setting IDs include only those settings and exclude unselected settings.
- [x] 1.3 Add Livewire tests proving `reports.access` users can see/use the company scope selector and unauthorized users remain denied.
- [x] 1.4 Add export parity tests proving selected setting IDs are passed to the export and export totals match the screen report scope.
- [x] 1.5 Add header/scope-label tests for one setting, partial multi-setting, and all-settings (`Semua Perusahaan`) scopes.

## 2. Report Scope Data Flow

- [x] 2.1 Add `selectedSettingIds` and available settings state to `App\Livewire\Reports\ProfitLossReport`.
- [x] 2.2 Implement a scope resolver that returns the current session setting when no settings are selected and normalized selected IDs otherwise.
- [x] 2.3 Add helper logic for resolving the human-readable scope label: single company, `Beberapa Perusahaan`, or `Semua Perusahaan`.
- [x] 2.4 Include effective `settingIds` and scope label data in the Livewire render and export filter payloads.

## 3. Service and Export Updates

- [x] 3.1 Change `OperationalProfitLossReportService::generate()` to accept selected setting IDs instead of a single setting ID.
- [x] 3.2 Update sales, sale return, purchase, purchase return, and expense queries to filter with the normalized setting ID list.
- [x] 3.3 Preserve IDR currency behavior for all selected scopes.
- [x] 3.4 Update `ProfitLossReportExport` to consume `settingIds`, call the shared service with those IDs, and render the correct company/scope header.
- [x] 3.5 Preserve the existing Excel filename behavior and operational row structure.

## 4. User Interface

- [x] 4.1 Add a company/settings multi-select control to `resources/views/livewire/reports/profit-loss-report.blade.php`.
- [x] 4.2 Show the current effective report scope on the screen, including `Semua Perusahaan` when all settings are selected.
- [x] 4.3 Ensure the filter and export buttons continue to work with the selected company scope.
- [x] 4.4 Keep the existing date filters, report table rows, negative formatting, and `reports.access` gate behavior unchanged.

## 5. Verification

- [x] 5.1 Run the focused profit/loss report test suite.
- [x] 5.2 Run a focused route/access test for `profit-loss-report.index`.
- [x] 5.3 Manually verify the page supports current setting, selected subset, and all-settings scopes.
- [x] 5.4 Run `openspec validate add-profit-loss-report-setting-scope --strict`.
