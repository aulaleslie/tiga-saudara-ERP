## 1. Shared Scope Foundations

- [x] 1.1 Add a reusable report setting-scope helper or trait that loads available settings, normalizes selected setting IDs, falls back to `session('setting_id')`, validates IDs against existing settings, and builds the scope label.
- [x] 1.2 Update existing report code to use unique business-source select element IDs or a shared Blade partial so the four new selectors do not collide with each other or with Laporan Laba Rugi.

## 2. Livewire Components and Views

- [x] 2.1 Add `selectedSettingIds` handling, effective setting scope calculation, available settings, and scope label data to `OperationalBalanceSheetReport`.
- [x] 2.2 Add `selectedSettingIds` handling, effective setting scope calculation, available settings, and scope label data to `OperationalGeneralLedgerReport`.
- [x] 2.3 Add `selectedSettingIds` handling, effective setting scope calculation, available settings, and scope label data to `OperationalCashFlowReport`.
- [x] 2.4 Add `selectedSettingIds` handling, effective setting scope calculation, available settings, and scope label data to `OperationalTrialBalanceReport`.
- [x] 2.5 Add the business source selector and visible scope label to the Neraca, Buku Besar, Arus Kas, and Neraca Saldo Livewire views.

## 3. Report Service Scope

- [x] 3.1 Update `OperationalMovementEventService` to accept an `int|array` setting scope, normalize it, and use `whereIn` for all direct and parent relationship setting filters.
- [x] 3.2 Update `OperationalGeneralLedgerReportService` to pass selected setting scope into `OperationalMovementEventService` while preserving bucket filtering, beginning balances, running balances, and ending balances.
- [x] 3.3 Update `OperationalTrialBalanceReportService` to pass selected setting scope into `OperationalMovementEventService` while preserving opening, period, ending, category, and grand total calculations.
- [x] 3.4 Update `OperationalCashFlowReportService` to accept selected setting scope for all period and opening cash queries, including sale payments, purchase payments, sale return payments, purchase return payments, and expenses.
- [x] 3.5 Update `OperationalBalanceSheetReportService` to accept selected setting scope for as-of sales, purchases, payments, sale returns, purchase returns, expenses, taxes, and product inventory valuation.
- [x] 3.6 Preserve existing legacy and Livewire purchase-return amount scaling behavior while widening purchase-return filters to selected setting scope.

## 4. Export Parity

- [x] 4.1 Update `OperationalBalanceSheetReportExport` to consume `settingIds`, regenerate the report with the selected scope, and render the scope label in the XLSX header.
- [x] 4.2 Update `OperationalGeneralLedgerReportExport` to consume `settingIds`, regenerate the report with the selected scope, and render the scope label in the XLSX output.
- [x] 4.3 Update `OperationalCashFlowReportExport` to consume `settingIds` for both XLSX and CSV exports and render the scope label in the XLSX header.
- [x] 4.4 Update `OperationalTrialBalanceReportExport` and `OperationalTrialBalanceReportCsvExport` to consume `settingIds`, regenerate the report with the selected scope, and render/export scoped results consistently.

## 5. Focused Tests

- [x] 5.1 Add tests proving each report defaults to the current `session('setting_id')` when no business source is selected.
- [x] 5.2 Add cross-business service tests proving selected settings are included and unselected settings are excluded for Neraca, Buku Besar, Arus Kas, and Neraca Saldo.
- [x] 5.3 Add opening/beginning balance tests proving Buku Besar, Arus Kas, and Neraca Saldo apply selected business scope to pre-period movement.
- [x] 5.4 Add export parity tests proving XLSX/CSV exports use the same selected setting IDs and totals as the screen reports.
- [x] 5.5 Keep or extend purchase-return scaling tests so legacy, edited legacy, settlement, and Livewire purchase returns remain correctly scaled under selected setting scope.

## 6. Verification

- [x] 6.1 Run focused report tests for the affected Livewire components, services, and exports.
- [x] 6.2 Run an OpenSpec validation/status check for `add-sekilas-business-report-scope`.
- [x] 6.3 Manually verify the four report screens show the selector, default current-setting scope, selected multi-business scope label, and matching export behavior.
