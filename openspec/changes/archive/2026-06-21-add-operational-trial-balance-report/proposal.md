## Why

The Reports landing page already lists `Neraca saldo`, and the sample files under `report-sample/neraca-saldo` define the target report shape, but the ERP does not yet provide an accounting-backed trial balance report. Because the application does not yet have complete operational journal posting, users need a transparent operational version that summarizes supported transaction movement without claiming full chart-of-account accuracy.

## What Changes

- Add an active `Neraca saldo` report under Reports > Sekilas bisnis for users with `reports.access`.
- Calculate opening debit/credit, period debit/credit, and ending debit/credit from supported operational movement sources scoped to the active `setting_id`.
- Present trial-balance-style rows grouped by operational account category, using synthetic operational report rows rather than true chart-of-account balances.
- Reuse or extract the movement normalization rules already used by the operational `Buku Besar` report so debit/credit semantics stay consistent.
- Add XLSX and CSV exports whose structure follows the provided `report-sample/neraca-saldo` examples and uses the same report data as the screen.
- Include a clear source note explaining that the report is calculated from operational transactions and does not yet use complete accounting journals or chart-of-account posting.
- Keep PDF export, custom report templates, real COA drill-down, and full accounting journal posting out of scope for this change.

## Capabilities

### New Capabilities
- `operational-trial-balance-report`: Provides an operational Neraca Saldo report with date filtering, grouped debit/credit balances, transparent source notes, and XLSX/CSV export.

### Modified Capabilities
- None.

## Impact

- Affected report UI: Reports landing page, Reports route/controller wrapper, and a new Livewire report screen.
- Affected report services: new trial balance service/value objects, likely sharing normalized operational movement logic with `OperationalGeneralLedgerReportService`.
- Affected exports: new XLSX and CSV export paths for the trial balance report.
- Affected tests: Reports landing, authorization, Livewire filtering, service calculations, export parity, empty state, and regression coverage for legacy/livewire return payment amount scaling.
- No database migration, new permission, or transaction lifecycle change is expected.
