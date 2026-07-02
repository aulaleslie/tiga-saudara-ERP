## 1. Report Calculation Foundations

- [ ] 1.1 Add focused service tests proving `Persediaan Barang` uses transaction-replayed stock as of the selected date and excludes future stock transactions.
- [ ] 1.2 Add focused service tests proving multi-setting Neraca sums inventory only across selected business sources.
- [ ] 1.3 Refactor `OperationalBalanceSheetReportService` inventory calculation to reuse existing warehouse stock valuation transaction replay semantics per selected setting.
- [ ] 1.4 Update the Neraca source note to describe transaction-replayed stock and average-cost valuation limitations.

## 2. Tax And Equity Rows

- [ ] 2.1 Add service tests for `PPN Masukan` from eligible purchase tax input and `PPN Keluaran` from eligible sales tax output within the selected as-of scope.
- [ ] 2.2 Implement supported tax row calculation and sample-aligned labels in the balance sheet service.
- [ ] 2.3 Add service tests for `Pendapatan sampai Tahun lalu`, `Pendapatan Periode ini`, and residual `Modal / Ekuitas`.
- [ ] 2.4 Implement prior-year earnings and current-year earnings using operational profit/loss formulas for the selected business-source scope.
- [ ] 2.5 Ensure total liabilities plus equity continues to equal total assets within currency rounding tolerance after earnings rows are added.

## 3. Report Model And UI

- [ ] 3.1 Adjust report value objects or row metadata so asset, liability, and equity sections can render the new rows without special-case Blade logic.
- [ ] 3.2 Update `operational-balance-sheet-report.blade.php` to show the revised row labels and operational source note.
- [ ] 3.3 Update Livewire tests for default render, custom as-of filtering, source note text, new rows, and preserved authorization.

## 4. XLSX And CSV Export

- [ ] 4.1 Update `OperationalBalanceSheetReportExport` so XLSX exports include the same new rows and source note as the screen.
- [ ] 4.2 Add `exportCsv` to the Neraca Livewire component using the same filters, scope label, and report output as the screen.
- [ ] 4.3 Make CSV output spreadsheet-friendly with section/row labels and numeric amount values.
- [ ] 4.4 Update the report view export control to expose CSV alongside XLSX.
- [ ] 4.5 Add export tests for XLSX row parity, CSV download filename, CSV row shape, numeric values, selected scope, and new tax/equity/inventory rows.

## 5. Regression Verification

- [ ] 5.1 Run focused Neraca service and Livewire tests.
- [ ] 5.2 Run focused warehouse stock valuation tests to confirm reused inventory logic remains unchanged.
- [ ] 5.3 Run focused profit/loss report tests to confirm earnings-source formulas remain unchanged.
- [ ] 5.4 Run `openspec validate improve-operational-balance-sheet-neraca --strict` or the repository's OpenSpec validation command.
