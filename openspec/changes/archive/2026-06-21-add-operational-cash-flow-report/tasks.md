## 1. Report Foundation

- [x] 1.1 Add operational cash-flow value objects for report, sections, rows, summary rows, and filter data under `app/Services/Reports`.
- [x] 1.2 Implement `OperationalCashFlowReportService` to load active setting currency/company context and return a complete direct-method report shape for any valid date range.
- [x] 1.3 Normalize supported operating cash events from sale payments, purchase payments, sale return payments, purchase return payments, and approved expenses.
- [x] 1.4 Reuse existing money-unit normalization rules for legacy/livewire return payment data where applicable.
- [x] 1.5 Calculate opening cash from prior supported cash events, period section totals, zero-valued bank revaluation, net cash movement, and ending cash.
- [x] 1.6 Keep investing and financing sections present with zero-valued first-version rows unless a reliable existing source is confirmed during implementation.

## 2. Livewire UI and Routing

- [x] 2.1 Add a Reports route, controller method, and Blade wrapper for the Arus Kas report guarded by `reports.access`.
- [x] 2.2 Add a Livewire `Reports\OperationalCashFlowReport` component with start date, end date, period preset, applied filter state, validation, reset, and export actions.
- [x] 2.3 Build the Arus Kas Blade view using existing Bootstrap/CoreUI report patterns with title, currency, source note, filters, export dropdown, sections, subtotals, and summary rows.
- [x] 2.4 Ensure invalid date ranges show validation errors and cannot be exported.
- [x] 2.5 Render zero-valued rows and summary rows when there is no supported cash movement.

## 3. Landing Navigation

- [x] 3.1 Update the Reports landing configuration so the `Arus kas` card has an active route and is no longer marked as a placeholder.
- [x] 3.2 Keep the Arus kas card gated by `reports.access` and under the `Sekilas bisnis` tab.
- [x] 3.3 Preserve existing behavior for other report cards and tabs.

## 4. Exports

- [x] 4.1 Add an `OperationalCashFlowReportExport` that uses the same service result as the screen.
- [x] 4.2 Implement XLSX export with company name, `Arus Kas` title, period label, currency label, direct-method rows, summary rows, and source note.
- [x] 4.3 Implement CSV export with sample-compatible columns: activity type, row label, and selected period label.
- [x] 4.4 Ensure XLSX and CSV filenames include the selected date range using the existing report filename style.

## 5. Verification

- [x] 5.1 Add service tests for sale payment inflow, purchase payment outflow, sale return refund outflow, purchase return refund inflow, and approved expense outflow.
- [x] 5.2 Add service tests for active-setting scoping, ineligible record exclusion, opening cash, net cash movement, bank revaluation placeholder, and ending cash reconciliation.
- [x] 5.3 Add Livewire/feature tests for authorization, default date range, period preset behavior, invalid date validation, empty movement display, and export actions.
- [x] 5.4 Add landing page tests proving the Arus kas card is actionable for `reports.access` users and hidden/forbidden for unauthorized users according to existing permission behavior.
- [x] 5.5 Add XLSX and CSV export parity tests for representative report rows and summary rows.
- [x] 5.6 Run focused report tests with `php artisan test` filters and, if practical, the project’s broader report test command.
