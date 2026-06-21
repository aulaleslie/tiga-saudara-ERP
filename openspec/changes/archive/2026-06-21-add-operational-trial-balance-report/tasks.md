## 1. Movement Source and Report Model

- [x] 1.1 Inspect `OperationalGeneralLedgerReportService` and identify the smallest reusable boundary for operational movement-event normalization.
- [x] 1.2 Extract or introduce a shared operational movement source that preserves existing Buku Besar eligibility, debit/credit direction, date ordering, setting scoping, and legacy/livewire return payment amount scaling.
- [x] 1.3 Keep existing Buku Besar behavior unchanged while routing it through the shared movement source or equivalent compatibility layer.
- [x] 1.4 Add trial-balance value objects for report metadata, category groups, rows, totals, and source note.
- [x] 1.5 Define stable operational row metadata, including category, label, normal balance direction, and synthetic account identifier or blank account identifier strategy.

## 2. Trial Balance Calculation Service

- [x] 2.1 Implement `OperationalTrialBalanceReportService` to calculate rows for a selected start date, end date, and active `setting_id`.
- [x] 2.2 Calculate opening debit/credit from supported movement before the start date.
- [x] 2.3 Calculate period debit/credit from supported movement within the selected date range.
- [x] 2.4 Calculate ending debit/credit from opening net movement plus period net movement using each row's normal balance direction.
- [x] 2.5 Calculate category and report totals across visible rows.
- [x] 2.6 Exclude unsupported manual `journal_items` from the operational report source.
- [x] 2.7 Return a clear empty report state when no supported movement or non-zero row balance exists.

## 3. Livewire Screen and Routing

- [x] 3.1 Add a controller wrapper and route for the Neraca saldo report using existing Reports module conventions and `reports.access`.
- [x] 3.2 Add a Livewire component for date range state, period presets, validation, apply/reset behavior, and export actions.
- [x] 3.3 Add a Blade report view with title, currency, source note, filters, empty state, category grouping, trial-balance columns, and totals.
- [x] 3.4 Activate the Reports landing `Neraca saldo` card by replacing placeholder behavior with the new route.
- [x] 3.5 Preserve existing Reports landing authorization behavior for users without `reports.access`.

## 4. XLSX and CSV Exports

- [x] 4.1 Add an XLSX export that uses the same report service output as the screen.
- [x] 4.2 Format XLSX with company name, title, period, currency, two-level trial-balance headers, category rows, data rows, totals, and operational source note.
- [x] 4.3 Add a CSV export that uses sample-compatible flat columns for category, account identifier, account label, opening debit/credit, period debit/credit, and ending debit/credit.
- [x] 4.4 Ensure CSV amount columns are numeric values suitable for spreadsheet import.
- [x] 4.5 Ensure export actions reject invalid date ranges instead of downloading stale or invalid data.

## 5. Focused Tests

- [x] 5.1 Add service tests for sale, sale payment, purchase, purchase payment, expense, completed return, and inactive/ineligible record handling.
- [x] 5.2 Add service tests for opening balance, period movement, ending balance, normal balance direction, category grouping, and totals.
- [x] 5.3 Add regression tests for legacy/livewire purchase return payment amount scaling through the shared movement source.
- [x] 5.4 Add a test proving manual `journal_items` do not create operational Neraca saldo rows.
- [x] 5.5 Add Livewire tests for default dates, applying valid filters, rejecting invalid date ranges, period presets, source note visibility, and empty state.
- [x] 5.6 Add Reports landing and authorization tests for the active Neraca saldo card and forbidden route access.
- [x] 5.7 Add export tests for XLSX and CSV parity with the on-screen report data.
- [x] 5.8 Add a compatibility regression test that representative Buku Besar output remains unchanged after movement-source extraction.

## 6. Verification

- [x] 6.1 Run the focused report and Livewire tests for the new Neraca saldo coverage.
- [x] 6.2 Run existing operational Buku Besar, Neraca, and Arus Kas report tests to catch shared movement regressions.
- [x] 6.3 Manually compare the UI and export structure against `report-sample/neraca-saldo/ui.txt`, CSV, and XLSX samples while keeping operational-source differences explicit.
- [x] 6.4 Run a broader `php artisan test` or `composer test:fresh-sqlite` pass if the shared movement extraction touches common report behavior.
